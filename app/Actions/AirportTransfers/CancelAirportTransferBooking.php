<?php

namespace App\Actions\AirportTransfers;

use App\Actions\AirportTransfers\Concerns\InteractsWithAirportTransferDomain;
use App\Enums\AccountStatus;
use App\Enums\AirportTransferBookingStatus;
use App\Enums\UserRole;
use App\Models\AirportTransferAssignment;
use App\Models\AirportTransferBooking;
use App\Models\User;
use App\Models\Vehicle;
use App\Notifications\AirportTransfers\AirportTransferBookingStatusNotification;
use App\Notifications\AirportTransfers\AirportTransferTeamAssignmentNotification;
use App\Services\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class CancelAirportTransferBooking
{
    use InteractsWithAirportTransferDomain;

    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function execute(
        User $actor,
        AirportTransferBooking $booking,
        ?string $reason = null,
    ): AirportTransferBooking {
        $reason = $this->nullableString($reason);

        Validator::make(
            ['reason' => $reason],
            ['reason' => ['nullable', 'string', 'max:2000']],
        )->validate();

        return DB::transaction(function () use ($actor, $booking, $reason): AirportTransferBooking {
            $lockedActor = User::query()->whereKey($actor->getKey())->lockForUpdate()->first();

            if ($lockedActor === null || $lockedActor->status !== AccountStatus::Active) {
                throw new AuthorizationException;
            }

            $expectedDriverId = $booking->assigned_driver_user_id;
            $expectedVehicleId = $booking->assigned_vehicle_id;
            $driver = $expectedDriverId === null
                ? null
                : User::query()->whereKey($expectedDriverId)->lockForUpdate()->first();
            $vehicle = $expectedVehicleId === null
                ? null
                : Vehicle::query()->whereKey($expectedVehicleId)->lockForUpdate()->first();
            $lockedBooking = AirportTransferBooking::query()
                ->with('customer')
                ->whereKey($booking->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedBooking->assigned_driver_user_id !== $expectedDriverId
                || $lockedBooking->assigned_vehicle_id !== $expectedVehicleId) {
                $this->invalid('assignment', 'The transfer team changed. Reload the booking and try again.');
            }

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

            if ($lockedBooking->status === AirportTransferBookingStatus::Cancelled) {
                return $lockedBooking;
            }

            if (! in_array($lockedBooking->status, [
                AirportTransferBookingStatus::Pending,
                AirportTransferBookingStatus::Confirmed,
            ], true)) {
                $this->invalid('status', 'This airport transfer can no longer be cancelled.');
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
            $cancelledAt = now();
            $assignments = AirportTransferAssignment::query()
                ->forBooking($lockedBooking)
                ->active()
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $assignments->each(function (AirportTransferAssignment $assignment) use (
                $lockedActor,
                $cancelledAt,
                $reason,
            ): void {
                $assignment->forceFill([
                    'unassigned_at' => $cancelledAt,
                    'unassigned_by_user_id' => $lockedActor->getKey(),
                    'unassignment_reason' => $reason,
                ])->save();
            });

            $lockedBooking->forceFill([
                'status' => AirportTransferBookingStatus::Cancelled,
                'cancellation_reason' => $reason,
                'cancelled_by_user_id' => $lockedActor->getKey(),
                'cancelled_at' => $cancelledAt,
                'assigned_vehicle_id' => null,
                'assigned_driver_user_id' => null,
            ])->save();

            $this->auditLogger->record(
                event: 'airport_transfer_booking.cancelled',
                auditable: $lockedBooking,
                oldValues: [
                    'status' => $oldStatus->value,
                    'assigned_vehicle_id' => $expectedVehicleId,
                    'assigned_driver_user_id' => $expectedDriverId,
                ],
                newValues: [
                    'status' => AirportTransferBookingStatus::Cancelled->value,
                    'cancelled_by_user_id' => $lockedActor->getKey(),
                    'cancelled_at' => $cancelledAt->toIso8601String(),
                    'assigned_vehicle_id' => null,
                    'assigned_driver_user_id' => null,
                    'released_assignments' => $assignments->count(),
                ],
                context: ['reason_present' => true],
                user: $lockedActor,
            );

            DB::afterCommit(function () use ($lockedBooking, $driver, $vehicle, $reason): void {
                $this->dispatchCancellationNotifications($lockedBooking, $driver, $vehicle, $reason);
            });

            return $lockedBooking->fresh([
                'customer',
                'airport',
                'location',
                'rate',
                'assignments',
            ]);
        }, 3);
    }

    private function dispatchCancellationNotifications(
        AirportTransferBooking $booking,
        ?User $driver,
        ?Vehicle $vehicle,
        string $reason,
    ): void {
        $this->notifyBookingRecipient($booking, new AirportTransferBookingStatusNotification(
            recipientName: $booking->contact_name,
            bookingReference: $booking->reference,
            statusLabel: AirportTransferBookingStatus::Cancelled->label(),
            serviceStartsAt: $booking->service_starts_at->toIso8601String(),
            viewUrl: $this->bookingViewUrl($booking),
            customerReason: $reason,
        ));

        if ($driver === null) {
            return;
        }

        $driver->notify(new AirportTransferTeamAssignmentNotification(
            recipientName: $driver->name,
            bookingReference: $booking->reference,
            serviceStartsAt: $booking->service_starts_at->toIso8601String(),
            airportName: $booking->airport_name_snapshot,
            locationName: $booking->location_name_snapshot,
            vehicleName: $vehicle === null
                ? $booking->vehicle_type_snapshot
                : trim($vehicle->year.' '.$vehicle->make.' '.$vehicle->model),
            assigned: false,
            forDriver: true,
            viewUrl: route('dashboard'),
            reason: 'Booking cancelled.',
        ));
    }
}
