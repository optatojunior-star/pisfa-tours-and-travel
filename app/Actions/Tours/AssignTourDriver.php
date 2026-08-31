<?php

namespace App\Actions\Tours;

use App\Actions\Tours\Concerns\InteractsWithTourDomain;
use App\Enums\AccountStatus;
use App\Enums\TourBookingStatus;
use App\Enums\UserRole;
use App\Models\CarHireDriverAssignment;
use App\Models\TourAssignment;
use App\Models\TourBooking;
use App\Models\User;
use App\Notifications\Tours\TourDriverAssignedNotification;
use App\Notifications\Tours\TourDriverUnassignedNotification;
use App\Services\AuditLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class AssignTourDriver
{
    use InteractsWithTourDomain;

    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function execute(
        User $actor,
        TourBooking $booking,
        ?User $driver,
        ?string $unassignmentReason = null,
    ): TourBooking {
        $unassignmentReason = $this->nullableString($unassignmentReason);

        Validator::make(
            ['unassignment_reason' => $unassignmentReason],
            ['unassignment_reason' => ['nullable', 'string', 'max:2000']],
        )->validate();

        return DB::transaction(function () use ($actor, $booking, $driver, $unassignmentReason): TourBooking {
            $lockedActor = User::query()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();
            $this->ensureOperationsActor($lockedActor);

            $lockedDriver = null;

            if ($driver !== null) {
                $lockedDriver = User::query()->whereKey($driver->getKey())->lockForUpdate()->first();

                if ($lockedDriver === null
                    || $lockedDriver->status !== AccountStatus::Active
                    || ! $lockedDriver->hasRole(UserRole::Driver)
                    || $lockedDriver->email_verified_at === null) {
                    $this->invalid('driver', 'Select an active, verified driver account.');
                }
            }

            $lockedBooking = TourBooking::query()
                ->with(['customer', 'assignedDriver'])
                ->whereKey($booking->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($lockedBooking->status, [TourBookingStatus::Confirmed, TourBookingStatus::InProgress], true)) {
                $this->invalid('driver', 'Drivers can be assigned only to confirmed or in-progress bookings.');
            }

            if ($lockedDriver !== null && ! $lockedBooking->departure_ends_at_snapshot->isFuture()) {
                $this->invalid('driver', 'A driver cannot be assigned after this tour has ended.');
            }

            /** @var Collection<int, TourAssignment> $activeAssignments */
            $activeAssignments = TourAssignment::query()
                ->where('tour_booking_id', $lockedBooking->getKey())
                ->active()
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $currentDriverIds = $activeAssignments
                ->pluck('driver_user_id')
                ->filter()
                ->map(static fn ($id): int => (int) $id)
                ->unique()
                ->values();

            if ($lockedDriver !== null
                && $lockedBooking->assigned_driver_user_id === $lockedDriver->getKey()
                && $currentDriverIds->count() === 1
                && $currentDriverIds->first() === $lockedDriver->getKey()) {
                return $lockedBooking;
            }

            if ($lockedDriver === null && $activeAssignments->isEmpty() && $lockedBooking->assigned_driver_user_id === null) {
                return $lockedBooking;
            }

            if ($lockedDriver === null && $unassignmentReason === null) {
                $this->invalid('unassignment_reason', 'Enter a reason for removing the driver assignment.');
            }

            if ($lockedDriver !== null) {
                $hasConflict = TourAssignment::query()
                    ->active()
                    ->forDriver($lockedDriver)
                    ->overlapping(
                        $lockedBooking->departure_starts_at_snapshot,
                        $lockedBooking->departure_ends_at_snapshot,
                    )
                    ->where('tour_booking_id', '!=', $lockedBooking->getKey())
                    ->exists();

                if ($hasConflict) {
                    $this->invalid('driver', 'This driver already has an overlapping tour assignment.');
                }

                $hasCarHireConflict = CarHireDriverAssignment::query()
                    ->active()
                    ->forDriver($lockedDriver)
                    ->overlapping(
                        $lockedBooking->departure_starts_at_snapshot,
                        $lockedBooking->departure_ends_at_snapshot,
                    )
                    ->exists();

                if ($hasCarHireConflict) {
                    $this->invalid('driver', 'This driver already has an overlapping vehicle-hire assignment.');
                }
            }

            $oldDriver = $lockedBooking->assignedDriver;
            $changedAt = now();
            $releaseReason = $unassignmentReason
                ?? ($lockedDriver === null ? 'Driver removed.' : 'Driver reassigned.');

            $activeAssignments->each(function (TourAssignment $assignment) use ($lockedActor, $changedAt, $releaseReason): void {
                $assignment->forceFill([
                    'unassigned_at' => $changedAt,
                    'unassigned_by_user_id' => $lockedActor->getKey(),
                    'unassignment_reason' => $releaseReason,
                ])->save();
            });

            if ($lockedDriver !== null) {
                TourAssignment::query()->create([
                    'tour_booking_id' => $lockedBooking->getKey(),
                    'driver_user_id' => $lockedDriver->getKey(),
                    'assigned_by_user_id' => $lockedActor->getKey(),
                    'starts_at' => $lockedBooking->departure_starts_at_snapshot,
                    'ends_at' => $lockedBooking->departure_ends_at_snapshot,
                    'assigned_at' => $changedAt,
                ]);
            }

            $lockedBooking->forceFill([
                'assigned_driver_user_id' => $lockedDriver?->getKey(),
            ])->save();

            $this->auditLogger->record(
                event: $lockedDriver === null ? 'tour_booking.driver_unassigned' : 'tour_booking.driver_assigned',
                auditable: $lockedBooking,
                oldValues: ['assigned_driver_user_id' => $oldDriver?->getKey()],
                newValues: [
                    'assigned_driver_user_id' => $lockedDriver?->getKey(),
                    'assignment_starts_at' => $lockedBooking->departure_starts_at_snapshot->toIso8601String(),
                    'assignment_ends_at' => $lockedBooking->departure_ends_at_snapshot->toIso8601String(),
                ],
                context: ['unassignment_reason' => $unassignmentReason],
                user: $lockedActor,
            );

            $customer = $lockedBooking->customer;
            $startsAt = $lockedBooking->departure_starts_at_snapshot->toIso8601String();

            DB::afterCommit(function () use (
                $oldDriver,
                $lockedDriver,
                $lockedBooking,
                $customer,
                $startsAt,
                $releaseReason,
            ): void {
                if ($oldDriver !== null && ($lockedDriver === null || ! $oldDriver->is($lockedDriver))) {
                    $oldDriver->notify(new TourDriverUnassignedNotification(
                        bookingReference: $lockedBooking->reference,
                        tourName: $lockedBooking->package_name_snapshot,
                        departureStartsAt: $startsAt,
                        reason: $releaseReason,
                        forDriver: true,
                    ));
                }

                if ($lockedDriver !== null) {
                    $customer->notify(new TourDriverAssignedNotification(
                        bookingReference: $lockedBooking->reference,
                        tourName: $lockedBooking->package_name_snapshot,
                        departureStartsAt: $startsAt,
                        counterpartName: $lockedDriver->name,
                        counterpartPhone: $lockedDriver->phone,
                        forDriver: false,
                    ));

                    $lockedDriver->notify(new TourDriverAssignedNotification(
                        bookingReference: $lockedBooking->reference,
                        tourName: $lockedBooking->package_name_snapshot,
                        departureStartsAt: $startsAt,
                        counterpartName: $lockedBooking->contact_name,
                        counterpartPhone: $lockedBooking->contact_phone,
                        forDriver: true,
                    ));
                } else {
                    $customer->notify(new TourDriverUnassignedNotification(
                        bookingReference: $lockedBooking->reference,
                        tourName: $lockedBooking->package_name_snapshot,
                        departureStartsAt: $startsAt,
                        reason: $releaseReason,
                        forDriver: false,
                    ));
                }
            });

            return $lockedBooking->fresh(['customer', 'assignedDriver', 'assignments', 'departure']);
        }, 3);
    }
}
