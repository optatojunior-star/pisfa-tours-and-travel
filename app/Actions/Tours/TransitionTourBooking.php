<?php

namespace App\Actions\Tours;

use App\Actions\Tours\Concerns\InteractsWithTourDomain;
use App\Enums\TourBookingEventType;
use App\Enums\TourBookingStatus;
use App\Enums\TourDepartureStatus;
use App\Models\TourAssignment;
use App\Models\TourBooking;
use App\Models\TourBookingEvent;
use App\Models\TourDeparture;
use App\Models\User;
use App\Notifications\Tours\TourBookingConfirmedNotification;
use App\Notifications\Tours\TourDriverUnassignedNotification;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

class TransitionTourBooking
{
    use InteractsWithTourDomain;

    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly CancelTourBooking $cancelBooking,
    ) {}

    public function execute(
        User $actor,
        TourBooking $booking,
        TourBookingStatus $nextStatus,
        ?string $reason = null,
    ): TourBooking {
        if ($nextStatus === TourBookingStatus::Cancelled) {
            return $this->cancelBooking->execute($actor, $booking, $reason);
        }

        $this->ensureOperationsActor($actor);

        return DB::transaction(function () use ($actor, $booking, $nextStatus): TourBooking {
            $lockedActor = User::query()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();
            $this->ensureOperationsActor($lockedActor);

            $departure = TourDeparture::query()
                ->whereKey($booking->tour_departure_id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedBooking = TourBooking::query()
                ->with(['customer', 'tourPackage', 'travelers', 'assignedDriver'])
                ->whereKey($booking->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedBooking->status === $nextStatus) {
                return $lockedBooking;
            }

            if (! $lockedBooking->canTransitionTo($nextStatus)) {
                $this->invalid(
                    'status',
                    "A {$lockedBooking->status->label()} booking cannot move to {$nextStatus->label()}.",
                );
            }

            $now = now();

            if (in_array($departure->status, [TourDepartureStatus::Cancelled, TourDepartureStatus::Completed], true)) {
                $this->invalid('status', 'The departure is not active.');
            }

            if ($nextStatus === TourBookingStatus::Confirmed) {
                if ($departure->status !== TourDepartureStatus::Scheduled || ! $departure->starts_at->isAfter($now)) {
                    $this->invalid('status', 'Only a future scheduled departure can be confirmed.');
                }

                if ($lockedBooking->travelers->count() !== $lockedBooking->traveler_count) {
                    $this->invalid('travelers', 'Complete the traveler list before confirming this booking.');
                }

                $reservedSeats = (int) $departure->bookings()->holdingCapacity()->sum('traveler_count');

                if ($reservedSeats > $departure->capacity) {
                    $this->invalid('capacity', 'This departure is over capacity and cannot be confirmed.');
                }
            }

            if ($nextStatus === TourBookingStatus::InProgress && $departure->starts_at->isAfter($now)) {
                $this->invalid('status', 'This tour cannot start before its departure time.');
            }

            if ($nextStatus === TourBookingStatus::Completed && $departure->ends_at->isAfter($now)) {
                $this->invalid('status', 'This tour cannot be completed before its scheduled end.');
            }

            $oldStatus = $lockedBooking->status;
            $oldDriver = $lockedBooking->assignedDriver;
            $releasedAssignments = 0;
            $changes = ['status' => $nextStatus];

            if ($nextStatus === TourBookingStatus::Confirmed) {
                $changes['confirmed_at'] = $now;
            } elseif ($nextStatus === TourBookingStatus::InProgress) {
                $changes['in_progress_at'] = $now;
            } elseif ($nextStatus === TourBookingStatus::Completed) {
                $changes['completed_at'] = $now;

                $activeAssignments = TourAssignment::query()
                    ->where('tour_booking_id', $lockedBooking->getKey())
                    ->active()
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
                $releasedAssignments = $activeAssignments->count();

                $activeAssignments->each(function (TourAssignment $assignment) use ($lockedActor, $now): void {
                    $assignment->forceFill([
                        'unassigned_at' => $now,
                        'unassigned_by_user_id' => $lockedActor->getKey(),
                        'unassignment_reason' => 'Tour completed.',
                    ])->save();
                });

                $changes['assigned_driver_user_id'] = null;
            }

            $lockedBooking->forceFill($changes)->save();

            if ($nextStatus === TourBookingStatus::Completed) {
                TourBookingEvent::query()->firstOrCreate(
                    [
                        'tour_booking_id' => $lockedBooking->getKey(),
                        'event_type' => TourBookingEventType::LoyaltyEligible->value,
                    ],
                    [
                        'payload' => [
                            'schema_version' => 1,
                            'booking_id' => $lockedBooking->getKey(),
                            'booking_reference' => $lockedBooking->reference,
                            'customer_id' => $lockedBooking->customer_id,
                            'total_minor' => $lockedBooking->total_minor,
                            'currency' => $lockedBooking->currency,
                            'completed_at' => $now->toIso8601String(),
                        ],
                        'processed_at' => null,
                    ],
                );
            }

            $this->auditLogger->record(
                event: 'tour_booking.status_changed',
                auditable: $lockedBooking,
                oldValues: [
                    'status' => $oldStatus->value,
                    'assigned_driver_user_id' => $oldDriver?->getKey(),
                ],
                newValues: [
                    'status' => $nextStatus->value,
                    'transitioned_at' => $now->toIso8601String(),
                    'loyalty_event_recorded' => $nextStatus === TourBookingStatus::Completed,
                    'assigned_driver_user_id' => $nextStatus === TourBookingStatus::Completed
                        ? null
                        : $lockedBooking->assigned_driver_user_id,
                    'released_assignments' => $releasedAssignments,
                ],
                user: $lockedActor,
            );

            if ($nextStatus === TourBookingStatus::Confirmed) {
                $customer = $lockedBooking->customer;

                DB::afterCommit(function () use ($customer, $lockedBooking): void {
                    $customer->notify(new TourBookingConfirmedNotification(
                        bookingReference: $lockedBooking->reference,
                        tourName: $lockedBooking->package_name_snapshot,
                        departureStartsAt: $lockedBooking->departure_starts_at_snapshot->toIso8601String(),
                        totalMinor: $lockedBooking->total_minor,
                        currency: $lockedBooking->currency,
                    ));
                });
            }

            if ($nextStatus === TourBookingStatus::Completed && $oldDriver !== null) {
                DB::afterCommit(function () use ($oldDriver, $lockedBooking): void {
                    $oldDriver->notify(new TourDriverUnassignedNotification(
                        bookingReference: $lockedBooking->reference,
                        tourName: $lockedBooking->package_name_snapshot,
                        departureStartsAt: $lockedBooking->departure_starts_at_snapshot->toIso8601String(),
                        reason: 'Tour completed.',
                        forDriver: true,
                    ));
                });
            }

            return $lockedBooking->fresh(['customer', 'tourPackage', 'departure', 'travelers', 'assignments', 'events']);
        }, 3);
    }
}
