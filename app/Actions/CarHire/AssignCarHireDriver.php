<?php

namespace App\Actions\CarHire;

use App\Actions\CarHire\Concerns\InteractsWithCarHireDomain;
use App\Enums\AccountStatus;
use App\Enums\CarHireBookingStatus;
use App\Enums\HireMode;
use App\Enums\UserRole;
use App\Models\CarHireBooking;
use App\Models\CarHireDriverAssignment;
use App\Models\TourAssignment;
use App\Models\User;
use App\Notifications\CarHire\CarHireDriverAssignmentNotification;
use App\Services\AuditLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class AssignCarHireDriver
{
    use InteractsWithCarHireDomain;

    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function execute(
        User $actor,
        CarHireBooking $booking,
        ?User $driver,
        ?string $unassignmentReason = null,
    ): CarHireBooking {
        $unassignmentReason = $this->nullableString($unassignmentReason);

        Validator::make(
            ['unassignment_reason' => $unassignmentReason],
            ['unassignment_reason' => ['nullable', 'string', 'max:2000']],
        )->validate();

        return DB::transaction(function () use ($actor, $booking, $driver, $unassignmentReason): CarHireBooking {
            $lockedActor = User::query()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();
            $this->ensureOperationsActor($lockedActor);

            // Locking the driver account is the shared serialization point for
            // both tour and car-hire assignments.
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

            $lockedBooking = CarHireBooking::query()
                ->with(['customer', 'assignedDriver'])
                ->whereKey($booking->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedBooking->hire_mode !== HireMode::WithDriver) {
                $this->invalid('driver', 'A driver cannot be assigned to a self-drive booking.');
            }

            if (! in_array($lockedBooking->status, [
                CarHireBookingStatus::Confirmed,
                CarHireBookingStatus::InProgress,
            ], true)) {
                $this->invalid('driver', 'Drivers can be assigned only to confirmed or in-progress bookings.');
            }

            if ($lockedDriver !== null && ! $lockedBooking->return_at->isFuture()) {
                $this->invalid('driver', 'A driver cannot be assigned after this hire has ended.');
            }

            /** @var Collection<int, CarHireDriverAssignment> $activeAssignments */
            $activeAssignments = CarHireDriverAssignment::query()
                ->where('car_hire_booking_id', $lockedBooking->getKey())
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

            if ($lockedDriver === null
                && $activeAssignments->isEmpty()
                && $lockedBooking->assigned_driver_user_id === null) {
                return $lockedBooking;
            }

            if ($lockedDriver === null && $unassignmentReason === null) {
                $this->invalid('unassignment_reason', 'Enter a reason for removing the driver assignment.');
            }

            if ($lockedDriver !== null) {
                $hasCarHireConflict = CarHireDriverAssignment::query()
                    ->active()
                    ->forDriver($lockedDriver)
                    ->overlapping($lockedBooking->pickup_at, $lockedBooking->return_at)
                    ->where('car_hire_booking_id', '!=', $lockedBooking->getKey())
                    ->exists();

                if ($hasCarHireConflict) {
                    $this->invalid('driver', 'This driver already has an overlapping vehicle-hire assignment.');
                }

                $hasTourConflict = TourAssignment::query()
                    ->active()
                    ->forDriver($lockedDriver)
                    ->overlapping($lockedBooking->pickup_at, $lockedBooking->return_at)
                    ->exists();

                if ($hasTourConflict) {
                    $this->invalid('driver', 'This driver already has an overlapping tour assignment.');
                }
            }

            $oldDriver = $lockedBooking->assignedDriver;
            $changedAt = now();
            $releaseReason = $unassignmentReason
                ?? ($lockedDriver === null ? 'Driver removed.' : 'Driver reassigned.');

            $activeAssignments->each(function (CarHireDriverAssignment $assignment) use (
                $lockedActor,
                $changedAt,
                $releaseReason,
            ): void {
                $assignment->forceFill([
                    'unassigned_at' => $changedAt,
                    'unassigned_by_user_id' => $lockedActor->getKey(),
                    'unassignment_reason' => $releaseReason,
                ])->save();
            });

            if ($lockedDriver !== null) {
                CarHireDriverAssignment::query()->create([
                    'car_hire_booking_id' => $lockedBooking->getKey(),
                    'driver_user_id' => $lockedDriver->getKey(),
                    'assigned_by_user_id' => $lockedActor->getKey(),
                    'starts_at' => $lockedBooking->pickup_at,
                    'ends_at' => $lockedBooking->return_at,
                    'assigned_at' => $changedAt,
                ]);
            }

            $lockedBooking->forceFill([
                'assigned_driver_user_id' => $lockedDriver?->getKey(),
            ])->save();

            $this->auditLogger->record(
                event: $lockedDriver === null
                    ? 'car_hire_booking.driver_unassigned'
                    : 'car_hire_booking.driver_assigned',
                auditable: $lockedBooking,
                oldValues: ['assigned_driver_user_id' => $oldDriver?->getKey()],
                newValues: [
                    'assigned_driver_user_id' => $lockedDriver?->getKey(),
                    'assignment_starts_at' => $lockedBooking->pickup_at->toIso8601String(),
                    'assignment_ends_at' => $lockedBooking->return_at->toIso8601String(),
                ],
                context: ['unassignment_reason' => $unassignmentReason],
                user: $lockedActor,
            );

            $customer = $lockedBooking->customer;

            DB::afterCommit(function () use (
                $oldDriver,
                $lockedDriver,
                $lockedBooking,
                $customer,
                $releaseReason,
            ): void {
                if ($oldDriver !== null && ($lockedDriver === null || ! $oldDriver->is($lockedDriver))) {
                    $oldDriver->notify(new CarHireDriverAssignmentNotification(
                        bookingReference: $lockedBooking->reference,
                        vehicleName: $lockedBooking->vehicle_name_snapshot,
                        pickupAt: $lockedBooking->pickup_at->toIso8601String(),
                        assigned: false,
                        forDriver: true,
                        reason: $releaseReason,
                    ));
                }

                if ($lockedDriver !== null) {
                    $customer->notify(new CarHireDriverAssignmentNotification(
                        bookingReference: $lockedBooking->reference,
                        vehicleName: $lockedBooking->vehicle_name_snapshot,
                        pickupAt: $lockedBooking->pickup_at->toIso8601String(),
                        assigned: true,
                        forDriver: false,
                        counterpartName: $lockedDriver->name,
                        counterpartPhone: $lockedDriver->phone,
                    ));

                    $lockedDriver->notify(new CarHireDriverAssignmentNotification(
                        bookingReference: $lockedBooking->reference,
                        vehicleName: $lockedBooking->vehicle_name_snapshot,
                        pickupAt: $lockedBooking->pickup_at->toIso8601String(),
                        assigned: true,
                        forDriver: true,
                        counterpartName: $lockedBooking->contact_name,
                        counterpartPhone: $lockedBooking->contact_phone,
                    ));
                } else {
                    $customer->notify(new CarHireDriverAssignmentNotification(
                        bookingReference: $lockedBooking->reference,
                        vehicleName: $lockedBooking->vehicle_name_snapshot,
                        pickupAt: $lockedBooking->pickup_at->toIso8601String(),
                        assigned: false,
                        forDriver: false,
                        reason: $releaseReason,
                    ));
                }
            });

            return $lockedBooking->fresh([
                'customer',
                'vehicle.coverMedia',
                'assignedDriver',
                'driverAssignments',
            ]);
        }, 3);
    }
}
