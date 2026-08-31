<?php

namespace App\Actions\Fleet;

use App\Enums\MaintenanceStatus;
use App\Enums\VehicleOperationalStatus;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleMaintenanceRecord;
use App\Services\AuditLogger;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Moves maintenance through its lifecycle.
 *
 * Starting work takes the vehicle off hire; completing or cancelling gives it
 * back — but only if no *other* open job is still holding it, which is why the
 * release is a check rather than an unconditional reset.
 */
class CompleteMaintenanceRecord
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /** Marks work as started and takes the vehicle out of service. */
    public function start(User $actor, VehicleMaintenanceRecord $record): VehicleMaintenanceRecord
    {
        return DB::transaction(function () use ($actor, $record): VehicleMaintenanceRecord {
            $lockedActor = FleetAccess::lockedManager($actor);
            [$locked, $vehicle] = $this->lockPair($record);

            if ($locked->status === MaintenanceStatus::InProgress) {
                return $locked;
            }

            $this->assertTransition($locked, MaintenanceStatus::InProgress);

            $locked->forceFill([
                'status' => MaintenanceStatus::InProgress,
                'started_at' => now(),
            ])->save();

            // A vehicle in the workshop must not be bookable.
            if ($vehicle->operational_status === VehicleOperationalStatus::Available) {
                $vehicle->forceFill([
                    'operational_status' => VehicleOperationalStatus::Maintenance,
                ])->save();
            }

            $this->auditLogger->record(
                event: 'vehicle_maintenance.started',
                auditable: $locked,
                oldValues: ['status' => MaintenanceStatus::Scheduled->value],
                newValues: [
                    'status' => MaintenanceStatus::InProgress->value,
                    'vehicle_operational_status' => $vehicle->fresh()->operational_status->value,
                ],
                user: $lockedActor,
            );

            return $locked->fresh('vehicle');
        }, 3);
    }

    /**
     * Closes the work with its real cost and odometer reading.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function complete(
        User $actor,
        VehicleMaintenanceRecord $record,
        array $attributes,
    ): VehicleMaintenanceRecord {
        return DB::transaction(function () use ($actor, $record, $attributes): VehicleMaintenanceRecord {
            $lockedActor = FleetAccess::lockedManager($actor);
            [$locked, $vehicle] = $this->lockPair($record);

            if ($locked->status === MaintenanceStatus::Completed) {
                return $locked;
            }

            $this->assertTransition($locked, MaintenanceStatus::Completed);

            $input = $this->validatedCompletion($attributes, $locked->currency);

            // The odometer guard runs against the locked vehicle, so a reading
            // that would move the fleet's mileage backwards is refused here
            // rather than corrupting every figure derived from it.
            RecordOdometerReading::advance($vehicle, $input['odometer_km']);

            $locked->forceFill([
                'status' => MaintenanceStatus::Completed,
                'completed_at' => now(),
                'odometer_km' => $input['odometer_km'],
                'cost_minor' => $input['cost_minor'],
                'vendor' => $input['vendor'] ?? $locked->vendor,
                // A one-off repair proposes no next due point; inventing one
                // would put noise in the alert queue.
                'next_due_on' => $locked->type->recurs() ? $input['next_due_on'] : null,
                'next_due_odometer_km' => $locked->type->recurs() ? $input['next_due_odometer_km'] : null,
                'due_alert_sent_at' => null,
            ])->save();

            $this->releaseVehicle($vehicle, $locked);

            $this->auditLogger->record(
                event: 'vehicle_maintenance.completed',
                auditable: $locked,
                newValues: [
                    'reference' => $locked->reference,
                    'cost_minor' => $locked->cost_minor,
                    'currency' => $locked->currency,
                    'odometer_km' => $locked->odometer_km,
                    'next_due_on' => $locked->next_due_on?->toDateString(),
                ],
                user: $lockedActor,
            );

            return $locked->fresh('vehicle');
        }, 3);
    }

    public function cancel(User $actor, VehicleMaintenanceRecord $record, string $reason): VehicleMaintenanceRecord
    {
        $reason = trim($reason);

        Validator::make(
            ['reason' => $reason],
            ['reason' => ['required', 'string', 'min:5', 'max:255']],
        )->validate();

        return DB::transaction(function () use ($actor, $record, $reason): VehicleMaintenanceRecord {
            $lockedActor = FleetAccess::lockedManager($actor);
            [$locked, $vehicle] = $this->lockPair($record);

            $this->assertTransition($locked, MaintenanceStatus::Cancelled);

            $previous = $locked->status;

            $locked->forceFill([
                'status' => MaintenanceStatus::Cancelled,
                'cancelled_at' => now(),
                'closure_reason' => $reason,
            ])->save();

            $this->releaseVehicle($vehicle, $locked);

            $this->auditLogger->record(
                event: 'vehicle_maintenance.cancelled',
                auditable: $locked,
                oldValues: ['status' => $previous->value],
                newValues: ['status' => MaintenanceStatus::Cancelled->value],
                user: $lockedActor,
            );

            return $locked->fresh('vehicle');
        }, 3);
    }

    /**
     * Returns the vehicle to service, but only when nothing else is holding it.
     *
     * An unconditional reset would put a vehicle back on hire while a second
     * open job was still in the workshop.
     */
    private function releaseVehicle(Vehicle $vehicle, VehicleMaintenanceRecord $closing): void
    {
        if ($vehicle->operational_status !== VehicleOperationalStatus::Maintenance) {
            return;
        }

        $stillHeld = VehicleMaintenanceRecord::query()
            ->where('vehicle_id', $vehicle->getKey())
            ->whereKeyNot($closing->getKey())
            ->where('status', MaintenanceStatus::InProgress->value)
            ->exists();

        if (! $stillHeld) {
            $vehicle->forceFill([
                'operational_status' => VehicleOperationalStatus::Available,
            ])->save();
        }
    }

    /** @return array{0: VehicleMaintenanceRecord, 1: Vehicle} */
    private function lockPair(VehicleMaintenanceRecord $record): array
    {
        // A fixed order — vehicle before record — everywhere in this class, so
        // two concurrent operations on the same vehicle cannot deadlock.
        $vehicle = Vehicle::query()
            ->whereKey($record->vehicle_id)
            ->lockForUpdate()
            ->firstOrFail();

        $locked = VehicleMaintenanceRecord::query()
            ->whereKey($record->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        $locked->setRelation('vehicle', $vehicle);

        return [$locked, $vehicle];
    }

    private function assertTransition(VehicleMaintenanceRecord $record, MaintenanceStatus $next): void
    {
        if (! $record->canTransitionTo($next)) {
            throw ValidationException::withMessages([
                'status' => "A {$record->status->label()} record cannot become {$next->label()}.",
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function validatedCompletion(array $attributes, string $currency): array
    {
        $validated = Validator::make($attributes, [
            'odometer_km' => ['required', 'integer', 'min:0', 'max:5000000'],
            'cost' => ['required', 'string', 'max:24'],
            'vendor' => ['nullable', 'string', 'max:180'],
            'next_due_on' => ['nullable', 'date', 'after:today'],
            'next_due_odometer_km' => ['nullable', 'integer', 'min:1', 'max:5000000'],
        ])->validate();

        try {
            $cost = Money::parse((string) $validated['cost'], $currency);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['cost' => $exception->getMessage()]);
        }

        $odometer = (int) $validated['odometer_km'];
        $nextOdometer = isset($validated['next_due_odometer_km'])
            ? (int) $validated['next_due_odometer_km']
            : null;

        // A next-service reading at or below today's is already past, so the
        // record would be born overdue.
        if ($nextOdometer !== null && $nextOdometer <= $odometer) {
            throw ValidationException::withMessages([
                'next_due_odometer_km' => 'The next service reading must be beyond the current '
                    .number_format($odometer).' km.',
            ]);
        }

        return [
            'odometer_km' => $odometer,
            'cost_minor' => $cost,
            'vendor' => isset($validated['vendor']) ? trim((string) $validated['vendor']) : null,
            'next_due_on' => $validated['next_due_on'] ?? null,
            'next_due_odometer_km' => $nextOdometer,
        ];
    }
}
