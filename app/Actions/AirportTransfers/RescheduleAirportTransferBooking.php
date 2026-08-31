<?php

namespace App\Actions\AirportTransfers;

use App\Actions\AirportTransfers\Concerns\InteractsWithAirportTransferDomain;
use App\Enums\AirportTransferBookingStatus;
use App\Enums\AirportTransferEventType;
use App\Enums\AirportTransferType;
use App\Models\AirportTransferAssignment;
use App\Models\AirportTransferBooking;
use App\Models\AirportTransferEvent;
use App\Models\AirportTransferRate;
use App\Models\CarHireBooking;
use App\Models\CarHireDriverAssignment;
use App\Models\TourAssignment;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class RescheduleAirportTransferBooking
{
    use InteractsWithAirportTransferDomain;

    public function __construct(private readonly AuditLogger $auditLogger) {}

    /** @param array<string, mixed> $attributes */
    public function execute(
        User $actor,
        AirportTransferBooking $booking,
        array $attributes,
    ): AirportTransferBooking {
        $this->ensureOperationsActor($actor);

        $validated = Validator::make($attributes, [
            'service_starts_at' => ['required'],
            'flight_scheduled_at' => ['sometimes', 'required'],
            'flight_number' => [
                'sometimes',
                'nullable',
                'string',
                'max:32',
                'regex:/\A[A-Za-z0-9][A-Za-z0-9\s-]*\z/',
            ],
            'reason' => ['required', 'string', 'max:2000'],
        ])->validate();

        $newStartsAt = $this->utcDateTime($validated['service_starts_at'], 'service_starts_at');
        $newFlightScheduledAt = array_key_exists('flight_scheduled_at', $validated)
            ? $this->utcDateTime($validated['flight_scheduled_at'], 'flight_scheduled_at')
            : null;
        $newFlightNumberWasProvided = array_key_exists('flight_number', $validated);
        $newFlightNumber = $newFlightNumberWasProvided
            ? $this->normalizedFlightNumber($validated['flight_number'] ?? null)
            : null;
        $reason = trim($validated['reason']);

        if (! $newStartsAt->isFuture()) {
            $this->invalid('service_starts_at', 'Select a future transfer time.');
        }

        if ($newStartsAt->isAfter(
            now()->addDays((int) config('airport_transfers.maximum_advance_days', 365)),
        )) {
            $this->invalid('service_starts_at', 'The transfer time is too far in advance.');
        }

        return DB::transaction(function () use (
            $actor,
            $booking,
            $newStartsAt,
            $newFlightScheduledAt,
            $newFlightNumberWasProvided,
            $newFlightNumber,
            $reason,
        ): AirportTransferBooking {
            $lockedActor = User::query()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();
            $this->ensureOperationsActor($lockedActor);

            $expectedDriverId = $booking->assigned_driver_user_id;
            $expectedVehicleId = $booking->assigned_vehicle_id;
            $driver = $expectedDriverId === null
                ? null
                : User::query()->whereKey($expectedDriverId)->lockForUpdate()->firstOrFail();
            $vehicle = $expectedVehicleId === null
                ? null
                : Vehicle::query()->whereKey($expectedVehicleId)->lockForUpdate()->firstOrFail();
            $lockedBooking = AirportTransferBooking::query()
                ->whereKey($booking->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedBooking->assigned_driver_user_id !== $expectedDriverId
                || $lockedBooking->assigned_vehicle_id !== $expectedVehicleId) {
                $this->invalid('assignment', 'The transfer team changed. Reload the booking and try again.');
            }

            if (! in_array($lockedBooking->status, [
                AirportTransferBookingStatus::Pending,
                AirportTransferBookingStatus::Confirmed,
            ], true)) {
                $this->invalid('status', 'Only pending or confirmed transfers can be rescheduled.');
            }

            if ($lockedBooking->status === AirportTransferBookingStatus::Pending
                && ! $lockedBooking->request_expires_at->isFuture()) {
                $this->invalid('status', 'This pending transfer request has expired.');
            }

            $rate = AirportTransferRate::query()
                ->whereKey($lockedBooking->airport_transfer_rate_id)
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertRateSnapshot($lockedBooking, $rate, $newStartsAt);

            $newEndsAt = $newStartsAt->addMinutes($lockedBooking->estimated_duration_minutes);
            $flightScheduledAt = $newFlightScheduledAt ?? $lockedBooking->flight_scheduled_at;

            if ($lockedBooking->transfer_type === AirportTransferType::Dropoff
                && ! $flightScheduledAt->isAfter($newEndsAt)) {
                $this->invalid(
                    'flight_scheduled_at',
                    'The scheduled flight must be after the estimated airport arrival time.',
                );
            }

            $assignments = AirportTransferAssignment::query()
                ->forBooking($lockedBooking)
                ->active()
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($assignments->count() > 1) {
                $this->invalid('assignment', 'This booking has inconsistent active assignment history.');
            }

            $assignment = $assignments->first();

            if ($assignment === null
                && ($expectedDriverId !== null || $expectedVehicleId !== null)) {
                $this->invalid('assignment', 'The booking assignment projection is inconsistent with its history.');
            }

            if ($assignment !== null) {
                if ($driver === null
                    || $vehicle === null
                    || $assignment->driver_user_id !== $driver->getKey()
                    || $assignment->vehicle_id !== $vehicle->getKey()) {
                    $this->invalid('assignment', 'The active assignment does not match the booking resources.');
                }

                $this->assertNoConflicts(
                    $lockedBooking,
                    $vehicle,
                    $driver,
                    $newStartsAt,
                    $newEndsAt,
                );
            }

            $oldStartsAt = $lockedBooking->service_starts_at;
            $oldEndsAt = $lockedBooking->service_ends_at;
            $oldFlightScheduledAt = $lockedBooking->flight_scheduled_at;
            $oldFlightNumber = $lockedBooking->flight_number;
            $requestExpiresAt = $lockedBooking->request_expires_at;

            if ($lockedBooking->status === AirportTransferBookingStatus::Pending
                && $requestExpiresAt->isAfter($newStartsAt)) {
                $requestExpiresAt = $newStartsAt;
            }

            $changes = [
                'service_starts_at' => $newStartsAt,
                'service_ends_at' => $newEndsAt,
                'cancellation_cutoff_at' => $newStartsAt->subHours(
                    (int) config('airport_transfers.default_cancellation_cutoff_hours', 4),
                ),
                'request_expires_at' => $requestExpiresAt,
            ];

            if ($newFlightScheduledAt !== null) {
                $changes['flight_scheduled_at'] = $newFlightScheduledAt;
            }

            if ($newFlightNumberWasProvided) {
                $changes['flight_number'] = $newFlightNumber;
            }

            $lockedBooking->forceFill($changes)->save();

            if ($assignment !== null) {
                $assignment->forceFill([
                    'starts_at' => $newStartsAt,
                    'ends_at' => $newEndsAt,
                ])->save();
            }

            // The current schema has one reminder marker per booking. Reset it
            // to retryable with the new schedule instead of silently suppressing
            // the reminder after a material reschedule.
            $reminder = AirportTransferEvent::query()
                ->where('airport_transfer_booking_id', $lockedBooking->getKey())
                ->where('event_type', AirportTransferEventType::PickupReminder->value)
                ->lockForUpdate()
                ->first();

            if ($reminder !== null) {
                $reminder->forceFill([
                    'payload' => [
                        'schema_version' => 1,
                        'booking_reference' => $lockedBooking->reference,
                        'service_starts_at' => $newStartsAt->toIso8601String(),
                        'rescheduled_at' => now()->toIso8601String(),
                    ],
                    'processed_at' => null,
                ])->save();
            }

            $this->auditLogger->record(
                event: 'airport_transfer_booking.rescheduled',
                auditable: $lockedBooking,
                oldValues: [
                    'service_starts_at' => $oldStartsAt->toIso8601String(),
                    'service_ends_at' => $oldEndsAt->toIso8601String(),
                    'flight_scheduled_at' => $oldFlightScheduledAt->toIso8601String(),
                    'flight_number_changed' => false,
                ],
                newValues: [
                    'service_starts_at' => $newStartsAt->toIso8601String(),
                    'service_ends_at' => $newEndsAt->toIso8601String(),
                    'flight_scheduled_at' => $lockedBooking->flight_scheduled_at->toIso8601String(),
                    'flight_number_changed' => $newFlightNumberWasProvided
                        && $oldFlightNumber !== $lockedBooking->flight_number,
                    'assignment_interval_updated' => $assignment !== null,
                    'pickup_reminder_reset' => $reminder !== null,
                ],
                context: ['reason_present' => $reason !== ''],
                user: $lockedActor,
            );

            return $lockedBooking->fresh([
                'customer',
                'airport',
                'location',
                'rate',
                'assignedVehicle',
                'assignedDriver',
                'assignments',
                'events',
            ]);
        }, 3);
    }

    private function assertRateSnapshot(
        AirportTransferBooking $booking,
        AirportTransferRate $rate,
        mixed $newStartsAt,
    ): void {
        if ($rate->airport_id !== $booking->airport_id
            || $rate->airport_transfer_location_id !== $booking->airport_transfer_location_id
            || $rate->transfer_type !== $booking->transfer_type
            || $rate->vehicle_type !== $booking->vehicle_type_snapshot
            || $rate->currency !== $booking->currency
            || $rate->amount_minor !== $booking->amount_minor
            || $rate->passenger_capacity !== $booking->passenger_capacity_snapshot
            || $rate->luggage_capacity !== $booking->luggage_capacity_snapshot
            || $rate->estimated_duration_minutes !== $booking->estimated_duration_minutes
            || ! $rate->isEffectiveAt($newStartsAt)) {
            $this->invalid(
                'service_starts_at',
                'The existing immutable price is not valid at the requested new time.',
            );
        }
    }

    private function assertNoConflicts(
        AirportTransferBooking $booking,
        Vehicle $vehicle,
        User $driver,
        mixed $startsAt,
        mixed $endsAt,
    ): void {
        $now = now();

        if (AirportTransferAssignment::query()
            ->active()
            ->forDriver($driver)
            ->overlapping($startsAt, $endsAt)
            ->where('airport_transfer_booking_id', '!=', $booking->getKey())
            ->whereHas('booking', fn ($query) => $query->holdingResources($now))
            ->exists()) {
            $this->invalid('service_starts_at', 'The assigned driver is unavailable at the new time.');
        }

        if (TourAssignment::query()->active()->forDriver($driver)->overlapping($startsAt, $endsAt)->exists()
            || CarHireDriverAssignment::query()->active()->forDriver($driver)->overlapping($startsAt, $endsAt)->exists()) {
            $this->invalid('service_starts_at', 'The assigned driver has another service at the new time.');
        }

        if (AirportTransferAssignment::query()
            ->active()
            ->forVehicle($vehicle)
            ->overlapping($startsAt, $endsAt)
            ->where('airport_transfer_booking_id', '!=', $booking->getKey())
            ->whereHas('booking', fn ($query) => $query->holdingResources($now))
            ->exists()) {
            $this->invalid('service_starts_at', 'The assigned vehicle is unavailable at the new time.');
        }

        if (CarHireBooking::query()
            ->where('vehicle_id', $vehicle->getKey())
            ->holdingVehicle($now)
            ->overlapping($startsAt, $endsAt)
            ->exists()) {
            $this->invalid('service_starts_at', 'The assigned vehicle has a hire booking at the new time.');
        }
    }

    private function normalizedFlightNumber(mixed $value): ?string
    {
        $value = $this->nullableString($value);

        return $value === null
            ? null
            : strtoupper((string) preg_replace('/\s+/', ' ', $value));
    }
}
