<?php

namespace App\Actions\Leasing;

use App\Enums\LeaseStatus;
use App\Enums\VehicleCatalogueStatus;
use App\Enums\VehicleOperationalStatus;
use App\Models\CarHireBooking;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleLease;
use App\Notifications\Leasing\LeaseStatusChangedNotification;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Starts, pauses, and ends a lease — and moves the vehicle with it.
 *
 * The lease and the fleet are one fact, not two. Activating puts the vehicle in
 * the fleet; suspending takes it off hire without ending the agreement; ending
 * retires it. A car that stayed bookable after its lease ended would have PISFA
 * hiring out something it no longer controls, and a car that never joined the
 * fleet would be a lease PISFA paid for and could not use.
 */
class TransitionVehicleLease
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * Brings the agreement into force and the vehicle into the fleet.
     *
     * The fleet record is created here rather than at application time so that
     * an offer PISFA never took up does not leave a phantom vehicle behind.
     */
    public function activate(User $actor, VehicleLease $lease): VehicleLease
    {
        return DB::transaction(function () use ($actor, $lease): VehicleLease {
            $lockedActor = LeasingAccess::lockedCommitter($actor);

            $locked = VehicleLease::query()
                ->whereKey($lease->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === LeaseStatus::Active) {
                return $locked;
            }

            $this->assertTransition($locked, LeaseStatus::Active);

            $vehicle = $locked->vehicle_id === null
                ? $this->addToFleet($locked)
                : $this->returnToFleet($locked->vehicle_id);

            $previous = $locked->status;

            $locked->forceFill([
                'status' => LeaseStatus::Active,
                'vehicle_id' => $vehicle->getKey(),
                // Kept if it ran before, so a lease suspended and resumed does
                // not lose the day it first came into force.
                'activated_at' => $locked->activated_at ?? now(),
                'suspended_at' => null,
                'suspension_reason' => null,
            ])->save();

            $this->auditLogger->record(
                event: 'vehicle_lease.activated',
                auditable: $locked,
                oldValues: ['status' => $previous->value],
                newValues: [
                    'status' => LeaseStatus::Active->value,
                    'vehicle_id' => $vehicle->getKey(),
                ],
                user: $lockedActor,
            );

            DB::afterCommit(fn () => $this->notify($locked, 'Your lease is now active and the vehicle is in service.'));

            return $locked->fresh(['owner', 'vehicle']);
        }, 3);
    }

    /**
     * Takes the vehicle off hire without ending the agreement.
     *
     * The terms survive — an accident or a lapsed insurance certificate should
     * not cost the owner the deal they negotiated — but the car stops being
     * bookable immediately.
     */
    public function suspend(User $actor, VehicleLease $lease, string $reason): VehicleLease
    {
        $reason = $this->validatedReason($reason);

        return DB::transaction(function () use ($actor, $lease, $reason): VehicleLease {
            $lockedActor = LeasingAccess::lockedManager($actor);

            $locked = VehicleLease::query()
                ->whereKey($lease->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === LeaseStatus::Suspended) {
                return $locked;
            }

            $this->assertTransition($locked, LeaseStatus::Suspended);

            $this->takeOffHire($locked->vehicle_id, VehicleOperationalStatus::Unavailable);

            $previous = $locked->status;

            $locked->forceFill([
                'status' => LeaseStatus::Suspended,
                'suspended_at' => now(),
                'suspension_reason' => $reason,
            ])->save();

            $this->auditLogger->record(
                event: 'vehicle_lease.suspended',
                auditable: $locked,
                oldValues: ['status' => $previous->value],
                newValues: ['status' => LeaseStatus::Suspended->value],
                user: $lockedActor,
            );

            DB::afterCommit(fn () => $this->notify($locked, 'Your vehicle has been taken off hire: '.$reason));

            return $locked->fresh(['owner', 'vehicle']);
        }, 3);
    }

    /**
     * Ends the agreement and retires the vehicle from the fleet.
     *
     * Refused while hires are still to run: the customer holding that booking
     * has been promised a car PISFA would no longer have.
     */
    public function end(User $actor, VehicleLease $lease, string $reason): VehicleLease
    {
        $reason = $this->validatedReason($reason);

        return DB::transaction(function () use ($actor, $lease, $reason): VehicleLease {
            $lockedActor = LeasingAccess::lockedCommitter($actor);

            $locked = VehicleLease::query()
                ->whereKey($lease->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === LeaseStatus::Ended) {
                return $locked;
            }

            $this->assertTransition($locked, LeaseStatus::Ended);

            $this->assertNoUnfinishedHires($locked);

            $this->takeOffHire($locked->vehicle_id, VehicleOperationalStatus::Retired, archive: true);

            $previous = $locked->status;

            $locked->forceFill([
                'status' => LeaseStatus::Ended,
                'ended_at' => now(),
                'termination_reason' => $reason,
                // The end date is what actually happened, which may be earlier
                // than the date the agreement was written for.
                'ends_on' => $locked->ends_on ?? now()->toDateString(),
            ])->save();

            $this->auditLogger->record(
                event: 'vehicle_lease.ended',
                auditable: $locked,
                oldValues: ['status' => $previous->value],
                newValues: [
                    'status' => LeaseStatus::Ended->value,
                    'vehicle_id' => $locked->vehicle_id,
                ],
                user: $lockedActor,
            );

            DB::afterCommit(fn () => $this->notify($locked, 'This lease has ended: '.$reason));

            return $locked->fresh(['owner', 'vehicle']);
        }, 3);
    }

    /**
     * A leased vehicle is not on hire while a booking is still to run.
     *
     * Uses the same "holding the vehicle" definition the hire domain does, so
     * the two cannot disagree about what counts as unfinished.
     */
    private function assertNoUnfinishedHires(VehicleLease $lease): void
    {
        if ($lease->vehicle_id === null) {
            return;
        }

        $unfinished = CarHireBooking::query()
            ->where('vehicle_id', $lease->vehicle_id)
            ->holdingVehicle()
            ->where('return_at', '>=', now())
            ->exists();

        if ($unfinished) {
            throw ValidationException::withMessages([
                'status' => 'This vehicle has hires that have not finished. '
                    .'Complete or cancel them before ending the lease — a customer is holding a booking for it.',
            ]);
        }
    }

    /** Creates the fleet record from the terms and the owner's description. */
    private function addToFleet(VehicleLease $lease): Vehicle
    {
        $lease->loadMissing('application');
        $source = $lease->application;

        if ($source === null) {
            throw ValidationException::withMessages([
                'vehicle_id' => 'This lease has no vehicle. Link the application it came from, '
                    .'or add the vehicle to the fleet first.',
            ]);
        }

        $plate = mb_strtoupper(trim($source->registration_plate));

        // A plate is unique across the fleet. A clash means the car is already
        // here — under another lease, or owned outright — and quietly creating a
        // second record would split its history in two.
        $existing = Vehicle::query()->where('registration_plate', $plate)->lockForUpdate()->first();

        if ($existing !== null) {
            throw ValidationException::withMessages([
                'vehicle_id' => 'A vehicle with that registration is already in the fleet. '
                    .'Link this lease to it rather than adding it twice.',
            ]);
        }

        $name = trim($source->year.' '.$source->make.' '.$source->model);

        $vehicle = new Vehicle;
        $vehicle->forceFill([
            'slug' => $this->uniqueSlug($name),
            'registration_plate' => $plate,
            'make' => $source->make,
            'model' => $source->model,
            'year' => $source->year,
            'color' => $source->colour,
            'condition' => $source->condition,
            'vehicle_type' => 'leased',
            'fuel_type' => $source->fuel_type,
            'transmission' => $source->transmission,
            'seating_capacity' => $source->seating_capacity ?? 5,
            'current_odometer_km' => $source->mileage_km ?? 0,
            'summary' => $name.' — leased vehicle.',
            'description' => $source->notes,
            // Draft, not published: it needs photographs, a hire rate, and
            // somebody's eye before the public sees it. Operationally available
            // so the desk can assign it straight away.
            'catalogue_status' => VehicleCatalogueStatus::Draft,
            'operational_status' => VehicleOperationalStatus::Available,
        ])->save();

        return $vehicle;
    }

    /** A suspended lease resuming: the same vehicle goes back on hire. */
    private function returnToFleet(int $vehicleId): Vehicle
    {
        $vehicle = Vehicle::query()->whereKey($vehicleId)->lockForUpdate()->firstOrFail();

        $vehicle->forceFill([
            'operational_status' => VehicleOperationalStatus::Available,
        ])->save();

        return $vehicle;
    }

    private function takeOffHire(?int $vehicleId, VehicleOperationalStatus $status, bool $archive = false): void
    {
        if ($vehicleId === null) {
            return;
        }

        $vehicle = Vehicle::query()->whereKey($vehicleId)->lockForUpdate()->first();

        if ($vehicle === null) {
            return;
        }

        $vehicle->forceFill(array_filter([
            'operational_status' => $status,
            // Unpublished as well as retired when the lease ends: leaving it
            // published would keep it in search results for a car PISFA no
            // longer has.
            'catalogue_status' => $archive ? VehicleCatalogueStatus::Archived : null,
        ], static fn (mixed $value): bool => $value !== null))->save();
    }

    private function uniqueSlug(string $source): string
    {
        $base = Str::slug($source) ?: 'vehicle';
        $slug = $base;
        $suffix = 1;

        while (Vehicle::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$suffix);
        }

        return $slug;
    }

    private function assertTransition(VehicleLease $lease, LeaseStatus $next): void
    {
        if (! $lease->canTransitionTo($next)) {
            throw ValidationException::withMessages([
                'status' => "A {$lease->status->label()} lease cannot become {$next->label()}.",
            ]);
        }
    }

    private function validatedReason(string $reason): string
    {
        $reason = trim($reason);

        Validator::make(
            ['reason' => $reason],
            ['reason' => ['required', 'string', 'min:5', 'max:255']],
        )->validate();

        return $reason;
    }

    private function notify(VehicleLease $lease, string $message): void
    {
        $lease->loadMissing('owner');

        $lease->owner?->notify(new LeaseStatusChangedNotification(
            reference: $lease->reference,
            statusLabel: $lease->status->label(),
            termsSummary: $lease->termsSummary(),
            message: $message,
        ));
    }
}
