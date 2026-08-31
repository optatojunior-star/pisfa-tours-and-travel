<?php

namespace App\Actions\Tours;

use App\Actions\Tours\Concerns\InteractsWithTourDomain;
use App\Enums\AccountStatus;
use App\Enums\TourBookingStatus;
use App\Enums\UserRole;
use App\Models\TourAssignment;
use App\Models\TourBooking;
use App\Models\TourDeparture;
use App\Models\User;
use App\Notifications\Tours\TourBookingCancelledNotification;
use App\Notifications\Tours\TourDriverUnassignedNotification;
use App\Services\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class CancelTourBooking
{
    use InteractsWithTourDomain;

    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function execute(User $actor, TourBooking $booking, ?string $reason = null): TourBooking
    {
        $reason = $this->nullableString($reason);

        Validator::make(
            ['reason' => $reason],
            ['reason' => ['nullable', 'string', 'max:2000']],
        )->validate();

        return DB::transaction(function () use ($actor, $booking, $reason): TourBooking {
            $lockedActor = User::query()->whereKey($actor->getKey())->lockForUpdate()->first();

            if ($lockedActor === null || $lockedActor->status !== AccountStatus::Active) {
                throw new AuthorizationException;
            }

            $departure = TourDeparture::query()
                ->whereKey($booking->tour_departure_id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedBooking = TourBooking::query()
                ->with(['customer', 'tourPackage', 'assignedDriver'])
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

            if ($lockedBooking->status === TourBookingStatus::Cancelled) {
                return $lockedBooking;
            }

            if (! in_array($lockedBooking->status, [TourBookingStatus::Pending, TourBookingStatus::Confirmed], true)) {
                $this->invalid('status', 'This booking can no longer be cancelled.');
            }

            if ($isOwner && ! $lockedBooking->canBeCancelledAt()) {
                $this->invalid('booking', 'The customer cancellation deadline has passed. Please contact PISFA for assistance.');
            }

            if ($reason === null) {
                $this->invalid('reason', 'Enter a reason for this cancellation.');
            }

            $oldStatus = $lockedBooking->status;
            $oldDriverId = $lockedBooking->assigned_driver_user_id;
            $cancelledAt = now();

            TourAssignment::query()
                ->where('tour_booking_id', $lockedBooking->getKey())
                ->active()
                ->lockForUpdate()
                ->get()
                ->each(function (TourAssignment $assignment) use ($lockedActor, $cancelledAt): void {
                    $assignment->forceFill([
                        'unassigned_at' => $cancelledAt,
                        'unassigned_by_user_id' => $lockedActor->getKey(),
                        'unassignment_reason' => 'Booking cancelled.',
                    ])->save();
                });

            $lockedBooking->forceFill([
                'status' => TourBookingStatus::Cancelled,
                'cancellation_reason' => $reason,
                'cancelled_by_user_id' => $lockedActor->getKey(),
                'cancelled_at' => $cancelledAt,
                'assigned_driver_user_id' => null,
            ])->save();

            $this->auditLogger->record(
                event: 'tour_booking.cancelled',
                auditable: $lockedBooking,
                oldValues: [
                    'status' => $oldStatus->value,
                    'assigned_driver_user_id' => $oldDriverId,
                ],
                newValues: [
                    'status' => TourBookingStatus::Cancelled->value,
                    'cancelled_by_user_id' => $lockedActor->getKey(),
                    'cancelled_at' => $cancelledAt->toIso8601String(),
                    'assigned_driver_user_id' => null,
                    'capacity_released' => $lockedBooking->traveler_count,
                ],
                context: ['reason' => $reason],
                user: $lockedActor,
            );

            $customer = $lockedBooking->customer;
            $assignedDriver = $lockedBooking->assignedDriver;
            $tourName = $lockedBooking->package_name_snapshot;
            $startsAt = $lockedBooking->departure_starts_at_snapshot->toIso8601String();

            DB::afterCommit(function () use ($customer, $assignedDriver, $lockedBooking, $tourName, $startsAt, $reason): void {
                $customer->notify(new TourBookingCancelledNotification(
                    bookingReference: $lockedBooking->reference,
                    tourName: $tourName,
                    departureStartsAt: $startsAt,
                    reason: $reason,
                ));

                if ($assignedDriver !== null) {
                    $assignedDriver->notify(new TourDriverUnassignedNotification(
                        bookingReference: $lockedBooking->reference,
                        tourName: $tourName,
                        departureStartsAt: $startsAt,
                        reason: 'Booking cancelled.',
                        forDriver: true,
                    ));
                }
            });

            return $lockedBooking->fresh(['customer', 'tourPackage', 'departure', 'travelers', 'assignments']);
        }, 3);
    }
}
