<?php

namespace App\Actions\CarHire;

use App\Actions\CarHire\Concerns\InteractsWithCarHireDomain;
use App\Enums\AccountStatus;
use App\Enums\CarHireBookingStatus;
use App\Enums\UserRole;
use App\Models\CarHireBooking;
use App\Models\CarHireContract;
use App\Models\CarHireDriverAssignment;
use App\Models\User;
use App\Models\Vehicle;
use App\Notifications\CarHire\CarHireBookingCancelledNotification;
use App\Notifications\CarHire\CarHireDriverAssignmentNotification;
use App\Services\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class CancelCarHireBooking
{
    use InteractsWithCarHireDomain;

    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function execute(User $actor, CarHireBooking $booking, ?string $reason = null): CarHireBooking
    {
        $reason = $this->nullableString($reason);

        Validator::make(
            ['reason' => $reason],
            ['reason' => ['nullable', 'string', 'max:2000']],
        )->validate();

        return DB::transaction(function () use ($actor, $booking, $reason): CarHireBooking {
            $lockedActor = User::query()->whereKey($actor->getKey())->lockForUpdate()->first();

            if ($lockedActor === null || $lockedActor->status !== AccountStatus::Active) {
                throw new AuthorizationException;
            }

            // Every availability mutation locks the vehicle before the booking so
            // confirmation, cancellation and new requests serialize per vehicle.
            Vehicle::query()->whereKey($booking->vehicle_id)->lockForUpdate()->firstOrFail();

            $lockedBooking = CarHireBooking::query()
                ->with(['customer', 'assignedDriver'])
                ->whereKey($booking->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $isOwner = $lockedBooking->customer_id === $lockedActor->getKey()
                && $lockedActor->hasRole(UserRole::Customer);
            $isOperationsActor = $lockedActor->hasAnyRole(
                UserRole::Staff,
                UserRole::Manager,
                UserRole::SuperAdmin,
            );

            if (! $isOwner && ! $isOperationsActor) {
                throw new AuthorizationException;
            }

            if ($lockedBooking->status === CarHireBookingStatus::Cancelled) {
                return $lockedBooking;
            }

            if (! in_array($lockedBooking->status, [
                CarHireBookingStatus::Pending,
                CarHireBookingStatus::Confirmed,
            ], true)) {
                $this->invalid('status', 'This vehicle-hire booking can no longer be cancelled.');
            }

            if ($isOwner && ! $lockedBooking->canBeCancelledAt()) {
                $this->invalid(
                    'booking',
                    'The customer cancellation deadline has passed. Please contact PISFA for assistance.',
                );
            }

            if ($reason === null) {
                $this->invalid('reason', 'Enter a reason for this cancellation.');
            }

            $oldStatus = $lockedBooking->status;
            $oldDriver = $lockedBooking->assignedDriver;
            $cancelledAt = now();

            $activeAssignments = CarHireDriverAssignment::query()
                ->where('car_hire_booking_id', $lockedBooking->getKey())
                ->active()
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $activeAssignments->each(function (CarHireDriverAssignment $assignment) use ($lockedActor, $cancelledAt): void {
                $assignment->forceFill([
                    'unassigned_at' => $cancelledAt,
                    'unassigned_by_user_id' => $lockedActor->getKey(),
                    'unassignment_reason' => 'Booking cancelled.',
                ])->save();
            });

            $contracts = CarHireContract::query()
                ->where('car_hire_booking_id', $lockedBooking->getKey())
                ->whereNull('voided_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $contracts->each(function (CarHireContract $contract) use ($lockedActor, $cancelledAt): void {
                $contract->forceFill([
                    'voided_at' => $cancelledAt,
                    'voided_by_user_id' => $lockedActor->getKey(),
                    'void_reason' => 'Booking cancelled.',
                ])->save();
            });

            $lockedBooking->forceFill([
                'status' => CarHireBookingStatus::Cancelled,
                'cancellation_reason' => $reason,
                'cancelled_by_user_id' => $lockedActor->getKey(),
                'cancelled_at' => $cancelledAt,
                'assigned_driver_user_id' => null,
            ])->save();

            $this->auditLogger->record(
                event: 'car_hire_booking.cancelled',
                auditable: $lockedBooking,
                oldValues: [
                    'status' => $oldStatus->value,
                    'assigned_driver_user_id' => $oldDriver?->getKey(),
                ],
                newValues: [
                    'status' => CarHireBookingStatus::Cancelled->value,
                    'cancelled_by_user_id' => $lockedActor->getKey(),
                    'cancelled_at' => $cancelledAt->toIso8601String(),
                    'assigned_driver_user_id' => null,
                    'released_assignments' => $activeAssignments->count(),
                    'voided_contracts' => $contracts->count(),
                ],
                context: ['reason' => $reason],
                user: $lockedActor,
            );

            $customer = $lockedBooking->customer;

            DB::afterCommit(function () use ($customer, $oldDriver, $lockedBooking, $reason): void {
                $customer->notify(new CarHireBookingCancelledNotification(
                    bookingReference: $lockedBooking->reference,
                    vehicleName: $lockedBooking->vehicle_name_snapshot,
                    pickupAt: $lockedBooking->pickup_at->toIso8601String(),
                    reason: $reason,
                ));

                if ($oldDriver !== null) {
                    $oldDriver->notify(new CarHireDriverAssignmentNotification(
                        bookingReference: $lockedBooking->reference,
                        vehicleName: $lockedBooking->vehicle_name_snapshot,
                        pickupAt: $lockedBooking->pickup_at->toIso8601String(),
                        assigned: false,
                        forDriver: true,
                        reason: 'Booking cancelled.',
                    ));
                }
            });

            return $lockedBooking->fresh([
                'customer',
                'vehicle.coverMedia',
                'selfDriveApplication',
                'contracts',
                'driverAssignments',
            ]);
        }, 3);
    }
}
