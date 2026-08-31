<?php

namespace App\Actions\AirportTransfers;

use App\Actions\AirportTransfers\Concerns\InteractsWithAirportTransferDomain;
use App\Enums\AccountStatus;
use App\Enums\AirportTransferBookingStatus;
use App\Enums\AirportTransferEventType;
use App\Enums\UserRole;
use App\Enums\VehicleOperationalStatus;
use App\Models\AirportTransferAssignment;
use App\Models\AirportTransferBooking;
use App\Models\AirportTransferEvent;
use App\Models\User;
use App\Models\Vehicle;
use App\Notifications\AirportTransfers\AirportTransferBookingConfirmedNotification;
use App\Notifications\AirportTransfers\AirportTransferBookingStatusNotification;
use App\Notifications\AirportTransfers\AirportTransferTeamAssignmentNotification;
use App\Services\AuditLogger;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class TransitionAirportTransferBooking
{
    use InteractsWithAirportTransferDomain;

    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly CancelAirportTransferBooking $cancelBooking,
    ) {}

    public function execute(
        User $actor,
        AirportTransferBooking $booking,
        AirportTransferBookingStatus $nextStatus,
        ?string $reason = null,
    ): AirportTransferBooking {
        if ($nextStatus === AirportTransferBookingStatus::Cancelled) {
            return $this->cancelBooking->execute($actor, $booking, $reason);
        }

        $reason = $this->nullableString($reason);

        Validator::make(
            ['reason' => $reason],
            ['reason' => ['nullable', 'string', 'max:2000']],
        )->validate();

        $this->ensureOperationsActor($actor);

        return DB::transaction(function () use ($actor, $booking, $nextStatus, $reason): AirportTransferBooking {
            $lockedActor = User::query()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();
            $this->ensureOperationsActor($lockedActor);

            // Shared transport lock order: driver, vehicle, then booking.
            $expectedDriverId = $booking->assigned_driver_user_id;
            $expectedVehicleId = $booking->assigned_vehicle_id;
            $driver = $expectedDriverId === null
                ? null
                : User::query()->whereKey($expectedDriverId)->lockForUpdate()->first();
            $vehicle = $expectedVehicleId === null
                ? null
                : Vehicle::query()->whereKey($expectedVehicleId)->lockForUpdate()->first();

            $lockedBooking = AirportTransferBooking::query()
                ->with(['customer', 'assignedDriver', 'assignedVehicle'])
                ->whereKey($booking->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedBooking->assigned_driver_user_id !== $expectedDriverId
                || $lockedBooking->assigned_vehicle_id !== $expectedVehicleId) {
                $this->invalid('assignment', 'The transfer team changed. Reload the booking and try again.');
            }

            if ($lockedBooking->status === $nextStatus) {
                return $lockedBooking;
            }

            if (! $lockedBooking->canTransitionTo($nextStatus)) {
                $this->invalid(
                    'status',
                    "A {$lockedBooking->status->label()} transfer cannot move to {$nextStatus->label()}.",
                );
            }

            $now = now();

            match ($nextStatus) {
                AirportTransferBookingStatus::Confirmed => $this->assertCanConfirm($lockedBooking, $driver, $vehicle, $now),
                AirportTransferBookingStatus::InProgress => $this->assertCanStart($lockedBooking, $driver, $vehicle, $now),
                AirportTransferBookingStatus::Completed => $this->assertCanComplete($lockedBooking, $now),
                AirportTransferBookingStatus::Declined => $this->assertCanDecline($reason),
                AirportTransferBookingStatus::Expired => $this->assertCanExpire($lockedBooking, $now),
                default => null,
            };

            $oldStatus = $lockedBooking->status;
            $releasedAssignments = collect();
            $changes = ['status' => $nextStatus];

            if ($nextStatus === AirportTransferBookingStatus::Confirmed) {
                $changes['confirmed_at'] = $now;
            } elseif ($nextStatus === AirportTransferBookingStatus::InProgress) {
                $changes['in_progress_at'] = $now;
            } elseif ($nextStatus === AirportTransferBookingStatus::Completed) {
                $changes['completed_at'] = $now;
                $changes['assigned_vehicle_id'] = null;
                $changes['assigned_driver_user_id'] = null;
                $releasedAssignments = $this->releaseAssignments(
                    $lockedBooking,
                    $lockedActor,
                    $now,
                    'Transfer completed.',
                );
            } elseif (in_array($nextStatus, [
                AirportTransferBookingStatus::Declined,
                AirportTransferBookingStatus::Expired,
            ], true)) {
                $terminalReason = $reason ?? 'The pending request expired before confirmation.';
                $changes['cancellation_reason'] = $terminalReason;
                $changes['assigned_vehicle_id'] = null;
                $changes['assigned_driver_user_id'] = null;
                $changes[$nextStatus === AirportTransferBookingStatus::Declined ? 'declined_at' : 'expired_at'] = $now;
                $releasedAssignments = $this->releaseAssignments(
                    $lockedBooking,
                    $lockedActor,
                    $now,
                    $terminalReason,
                );
            }

            $lockedBooking->forceFill($changes)->save();

            if ($nextStatus === AirportTransferBookingStatus::Expired) {
                AirportTransferEvent::query()->firstOrCreate(
                    [
                        'airport_transfer_booking_id' => $lockedBooking->getKey(),
                        'event_type' => AirportTransferEventType::BookingExpired->value,
                    ],
                    [
                        'payload' => [
                            'schema_version' => 1,
                            'booking_reference' => $lockedBooking->reference,
                            'request_expired_at' => $lockedBooking->request_expires_at->toIso8601String(),
                            'assignment_id' => $releasedAssignments->first()?->getKey(),
                        ],
                        'processed_at' => null,
                    ],
                );
            }

            if ($nextStatus === AirportTransferBookingStatus::Completed) {
                AirportTransferEvent::query()->firstOrCreate(
                    [
                        'airport_transfer_booking_id' => $lockedBooking->getKey(),
                        'event_type' => AirportTransferEventType::LoyaltyEligible->value,
                    ],
                    [
                        'payload' => [
                            'schema_version' => 1,
                            'booking_id' => $lockedBooking->getKey(),
                            'booking_reference' => $lockedBooking->reference,
                            'customer_id' => $lockedBooking->customer_id,
                            'amount_minor' => $lockedBooking->amount_minor,
                            'currency' => $lockedBooking->currency,
                            'completed_at' => $now->toIso8601String(),
                        ],
                        'processed_at' => null,
                    ],
                );
            }

            $this->auditLogger->record(
                event: 'airport_transfer_booking.status_changed',
                auditable: $lockedBooking,
                oldValues: [
                    'status' => $oldStatus->value,
                    'assigned_vehicle_id' => $expectedVehicleId,
                    'assigned_driver_user_id' => $expectedDriverId,
                ],
                newValues: [
                    'status' => $nextStatus->value,
                    'transitioned_at' => $now->toIso8601String(),
                    'assigned_vehicle_id' => $lockedBooking->assigned_vehicle_id,
                    'assigned_driver_user_id' => $lockedBooking->assigned_driver_user_id,
                    'released_assignments' => $releasedAssignments->count(),
                    'loyalty_event_recorded' => $nextStatus === AirportTransferBookingStatus::Completed,
                ],
                context: ['reason_present' => $reason !== null],
                user: $lockedActor,
            );

            DB::afterCommit(fn () => $this->dispatchNotifications(
                $lockedBooking,
                $nextStatus,
                $driver,
                $vehicle,
                $reason,
            ));

            return $lockedBooking->fresh([
                'customer',
                'airport',
                'location',
                'rate',
                'assignedVehicle',
                'assignedDriver',
                'assignments',
            ]);
        }, 3);
    }

    private function assertCanConfirm(
        AirportTransferBooking $booking,
        ?User $driver,
        ?Vehicle $vehicle,
        DateTimeInterface $now,
    ): void {
        if (! $booking->request_expires_at->isAfter($now)) {
            $this->invalid('status', 'This pending request has passed its review deadline.');
        }

        if (! $booking->service_starts_at->isAfter($now)) {
            $this->invalid('status', 'Only a future transfer can be confirmed.');
        }

        $this->assertOperationalTeam($booking, $driver, $vehicle, 'confirming');
    }

    private function assertCanStart(
        AirportTransferBooking $booking,
        ?User $driver,
        ?Vehicle $vehicle,
        DateTimeInterface $now,
    ): void {
        if ($booking->service_starts_at->isAfter($now)) {
            $this->invalid('status', 'This transfer cannot start before its scheduled service time.');
        }

        $this->assertOperationalTeam($booking, $driver, $vehicle, 'starting');
    }

    private function assertCanComplete(AirportTransferBooking $booking, DateTimeInterface $now): void
    {
        if ($booking->service_starts_at->isAfter($now)) {
            $this->invalid('status', 'This transfer cannot be completed before its scheduled service time.');
        }
    }

    /** @return never|void */
    private function assertCanDecline(?string $reason): void
    {
        if ($reason === null) {
            $this->invalid('reason', 'Enter a reason for declining this transfer request.');
        }
    }

    private function assertCanExpire(AirportTransferBooking $booking, DateTimeInterface $now): void
    {
        if ($booking->request_expires_at->isAfter($now)) {
            $this->invalid('status', 'This pending request has not reached its review deadline yet.');
        }
    }

    private function assertOperationalTeam(
        AirportTransferBooking $booking,
        ?User $driver,
        ?Vehicle $vehicle,
        string $intent,
    ): void {
        if ($driver === null || $vehicle === null) {
            $this->invalid('assignment', "Assign a vehicle and driver before {$intent} this transfer.");
        }

        if ($driver->status !== AccountStatus::Active
            || ! $driver->hasRole(UserRole::Driver)
            || $driver->email_verified_at === null) {
            $this->invalid('driver', 'The assigned driver account is no longer active and verified.');
        }

        if ($vehicle->operational_status !== VehicleOperationalStatus::Available) {
            $this->invalid('vehicle', 'The assigned vehicle is not operationally available.');
        }

        if ($vehicle->seating_capacity < $booking->passenger_count) {
            $this->invalid('vehicle', 'The assigned vehicle cannot carry the booked passenger count.');
        }

        $activeAssignments = AirportTransferAssignment::query()
            ->forBooking($booking)
            ->active()
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($activeAssignments->count() !== 1
            || (int) $activeAssignments->first()->driver_user_id !== $driver->getKey()
            || (int) $activeAssignments->first()->vehicle_id !== $vehicle->getKey()) {
            $this->invalid('assignment', 'The active transfer assignment is incomplete or inconsistent.');
        }
    }

    /** @return Collection<int, AirportTransferAssignment> */
    private function releaseAssignments(
        AirportTransferBooking $booking,
        User $actor,
        DateTimeInterface $releasedAt,
        string $reason,
    ): Collection {
        $assignments = AirportTransferAssignment::query()
            ->forBooking($booking)
            ->active()
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $assignments->each(function (AirportTransferAssignment $assignment) use ($actor, $releasedAt, $reason): void {
            $assignment->forceFill([
                'unassigned_at' => $releasedAt,
                'unassigned_by_user_id' => $actor->getKey(),
                'unassignment_reason' => $reason,
            ])->save();
        });

        return $assignments;
    }

    private function dispatchNotifications(
        AirportTransferBooking $booking,
        AirportTransferBookingStatus $status,
        ?User $driver,
        ?Vehicle $vehicle,
        ?string $reason,
    ): void {
        $vehicleName = $vehicle === null
            ? $booking->vehicle_type_snapshot
            : trim($vehicle->year.' '.$vehicle->make.' '.$vehicle->model);

        if ($status === AirportTransferBookingStatus::Confirmed) {
            $this->notifyBookingRecipient($booking, new AirportTransferBookingConfirmedNotification(
                recipientName: $booking->contact_name,
                bookingReference: $booking->reference,
                transferLabel: $booking->transfer_type->label(),
                airportName: $booking->airport_name_snapshot,
                locationName: $booking->location_name_snapshot,
                serviceStartsAt: $booking->service_starts_at->toIso8601String(),
                amountMinor: $booking->amount_minor,
                currency: $booking->currency,
                vehicleName: $vehicleName,
                driverName: $driver?->name ?? 'To be introduced on arrival',
                viewUrl: $this->bookingViewUrl($booking),
            ));

            return;
        }

        $this->notifyBookingRecipient($booking, new AirportTransferBookingStatusNotification(
            recipientName: $booking->contact_name,
            bookingReference: $booking->reference,
            statusLabel: $status->label(),
            serviceStartsAt: $booking->service_starts_at->toIso8601String(),
            viewUrl: $this->bookingViewUrl($booking),
            customerReason: $reason,
        ));

        if ($driver === null || ! in_array($status, [
            AirportTransferBookingStatus::Completed,
            AirportTransferBookingStatus::Declined,
            AirportTransferBookingStatus::Expired,
        ], true)) {
            return;
        }

        $driver->notify(new AirportTransferTeamAssignmentNotification(
            recipientName: $driver->name,
            bookingReference: $booking->reference,
            serviceStartsAt: $booking->service_starts_at->toIso8601String(),
            airportName: $booking->airport_name_snapshot,
            locationName: $booking->location_name_snapshot,
            vehicleName: $vehicleName,
            assigned: false,
            forDriver: true,
            viewUrl: route('dashboard'),
            reason: match ($status) {
                AirportTransferBookingStatus::Completed => 'Transfer completed.',
                AirportTransferBookingStatus::Declined => $reason ?? 'Transfer request declined.',
                default => 'The pending request expired before confirmation.',
            },
        ));
    }
}
