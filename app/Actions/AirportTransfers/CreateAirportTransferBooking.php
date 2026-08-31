<?php

namespace App\Actions\AirportTransfers;

use App\Actions\AirportTransfers\Concerns\InteractsWithAirportTransferDomain;
use App\Enums\AirportTransferBookingStatus;
use App\Enums\AirportTransferType;
use App\Models\Airport;
use App\Models\AirportTransferBooking;
use App\Models\AirportTransferLocation;
use App\Models\AirportTransferRate;
use App\Models\User;
use App\Notifications\AirportTransfers\AirportTransferBookingReceivedNotification;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use JsonException;

class CreateAirportTransferBooking
{
    use InteractsWithAirportTransferDomain;

    public function __construct(private readonly AuditLogger $auditLogger) {}

    /** @param array<string, mixed> $attributes */
    public function execute(
        ?User $customer,
        Airport $airport,
        AirportTransferLocation $location,
        array $attributes,
        string $idempotencyKey,
    ): AirportTransferBooking {
        $input = $this->validatedInput($customer, $airport, $location, $attributes, $idempotencyKey);

        return DB::transaction(function () use ($customer, $airport, $location, $input): AirportTransferBooking {
            $lockedCustomer = null;

            if ($customer !== null) {
                $lockedCustomer = User::query()
                    ->whereKey($customer->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();
                $this->ensureBookingCustomer($lockedCustomer);
            }

            // The airport row serializes anonymous duplicate submissions that
            // do not have a customer row available as their owner lock.
            $lockedAirport = Airport::query()->whereKey($airport->getKey())->lockForUpdate()->firstOrFail();
            $lockedLocation = AirportTransferLocation::query()
                ->whereKey($location->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $existing = AirportTransferBooking::query()
                ->where('idempotency_owner_hash', $input['idempotency_owner_hash'])
                ->where('idempotency_key', $input['idempotency_key'])
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if ($existing->airport_id !== $lockedAirport->getKey()
                    || $existing->airport_transfer_location_id !== $lockedLocation->getKey()
                    || ! hash_equals($existing->request_fingerprint, $input['request_fingerprint'])) {
                    $this->invalid(
                        'idempotency_key',
                        'This idempotency key was already used for a different transfer request.',
                    );
                }

                return $existing->load(['airport', 'location', 'rate', 'assignedVehicle', 'assignedDriver']);
            }

            if (! $lockedAirport->is_active) {
                $this->invalid('airport', 'The selected airport is not accepting transfer requests.');
            }

            if (! $lockedLocation->is_active) {
                $this->invalid('location', 'The selected service location is not accepting transfer requests.');
            }

            $rate = AirportTransferRate::query()
                ->forAirport($lockedAirport)
                ->forLocation($lockedLocation)
                ->forTransferType($input['transfer_type'])
                ->forVehicleType($input['vehicle_type'])
                ->forCurrency($input['currency'])
                ->active()
                ->effectiveAt($input['service_starts_at'])
                ->latest('effective_from')
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if ($rate === null) {
                $this->invalid(
                    'vehicle_type',
                    'The selected route, vehicle type and currency are not priced for that pickup time.',
                );
            }

            if (! $rate->supportsParty($input['passenger_count'], $input['luggage_count'])) {
                $this->invalid(
                    'passenger_count',
                    'The selected vehicle type cannot carry this passenger and luggage count.',
                );
            }

            if ($rate->amount_minor < 1) {
                $this->invalid('vehicle_type', 'The selected transfer rate is invalid.');
            }

            $serviceEndsAt = $input['service_starts_at']->addMinutes($rate->estimated_duration_minutes);

            if (! $serviceEndsAt->isAfter($input['service_starts_at'])) {
                $this->invalid('service_starts_at', 'The selected transfer duration is invalid.');
            }

            if ($input['transfer_type'] === AirportTransferType::Dropoff
                && ! $input['flight_scheduled_at']->isAfter($serviceEndsAt)) {
                $this->invalid(
                    'flight_scheduled_at',
                    'The scheduled flight must be after the estimated airport arrival time.',
                );
            }

            $now = now()->toImmutable();
            $requestExpiresAt = $now->addMinutes(
                (int) config('airport_transfers.request_expiry_minutes', 1440),
            );

            if ($requestExpiresAt->isAfter($input['service_starts_at'])) {
                $requestExpiresAt = $input['service_starts_at'];
            }

            $booking = new AirportTransferBooking;
            $booking->forceFill([
                'reference' => 'TRNSF-'.Str::upper((string) Str::ulid()),
                'customer_id' => $lockedCustomer?->getKey(),
                'airport_id' => $lockedAirport->getKey(),
                'airport_transfer_location_id' => $lockedLocation->getKey(),
                'airport_transfer_rate_id' => $rate->getKey(),
                'idempotency_owner_hash' => $input['idempotency_owner_hash'],
                'idempotency_key' => $input['idempotency_key'],
                'request_fingerprint' => $input['request_fingerprint'],
                'status' => AirportTransferBookingStatus::Pending,
                'transfer_type' => $input['transfer_type'],
                'airport_code_snapshot' => $lockedAirport->code,
                'airport_name_snapshot' => $lockedAirport->name,
                'location_name_snapshot' => $lockedLocation->name,
                'vehicle_type_snapshot' => $rate->vehicle_type,
                'passenger_capacity_snapshot' => $rate->passenger_capacity,
                'luggage_capacity_snapshot' => $rate->luggage_capacity,
                'amount_minor' => $rate->amount_minor,
                'currency' => $rate->currency,
                'estimated_duration_minutes' => $rate->estimated_duration_minutes,
                'service_starts_at' => $input['service_starts_at'],
                'service_ends_at' => $serviceEndsAt,
                'cancellation_cutoff_at' => $input['service_starts_at']->subHours(
                    (int) config('airport_transfers.default_cancellation_cutoff_hours', 4),
                ),
                'request_expires_at' => $requestExpiresAt,
                'flight_number' => $input['flight_number'],
                'flight_scheduled_at' => $input['flight_scheduled_at'],
                'passenger_count' => $input['passenger_count'],
                'luggage_count' => $input['luggage_count'],
                'service_address' => $input['service_address'],
                'contact_name' => $input['contact_name'],
                'contact_email' => $input['contact_email'],
                'contact_phone' => $input['contact_phone'],
                'special_requests' => $input['special_requests'],
            ])->save();

            $this->auditLogger->record(
                event: 'airport_transfer_booking.created',
                auditable: $booking,
                newValues: [
                    'reference' => $booking->reference,
                    'customer_id' => $booking->customer_id,
                    'airport_id' => $booking->airport_id,
                    'airport_transfer_location_id' => $booking->airport_transfer_location_id,
                    'airport_transfer_rate_id' => $booking->airport_transfer_rate_id,
                    'status' => AirportTransferBookingStatus::Pending->value,
                    'transfer_type' => $booking->transfer_type->value,
                    'vehicle_type' => $booking->vehicle_type_snapshot,
                    'passenger_count' => $booking->passenger_count,
                    'luggage_count' => $booking->luggage_count,
                    'amount_minor' => $booking->amount_minor,
                    'currency' => $booking->currency,
                    'service_starts_at' => $booking->service_starts_at->toIso8601String(),
                    'service_ends_at' => $booking->service_ends_at->toIso8601String(),
                    'request_expires_at' => $booking->request_expires_at->toIso8601String(),
                    'guest_request' => $booking->isGuest(),
                ],
                user: $lockedCustomer,
            );

            DB::afterCommit(fn () => $this->dispatchReceivedNotification($booking));

            return $booking->load(['airport', 'location', 'rate']);
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function validatedInput(
        ?User $customer,
        Airport $airport,
        AirportTransferLocation $location,
        array $attributes,
        string $idempotencyKey,
    ): array {
        if ($customer !== null) {
            $attributes = array_merge($attributes, [
                'contact_name' => $customer->name,
                'contact_email' => $customer->email,
                'contact_phone' => $customer->phone,
            ]);
        }

        $attributes['idempotency_key'] = trim($idempotencyKey);
        $validated = Validator::make($attributes, [
            'transfer_type' => ['required', Rule::enum(AirportTransferType::class)],
            'vehicle_type' => ['required', 'string', 'max:40'],
            'currency' => ['required', 'string', 'size:3'],
            'service_starts_at' => ['nullable'],
            'flight_number' => [
                'nullable',
                'string',
                'max:32',
                'regex:/\A[A-Za-z0-9][A-Za-z0-9\s-]*\z/',
            ],
            'flight_scheduled_at' => ['required'],
            'passenger_count' => [
                'required',
                'integer',
                'min:1',
                'max:'.(int) config('airport_transfers.maximum_passengers', 50),
            ],
            'luggage_count' => [
                'required',
                'integer',
                'min:0',
                'max:'.(int) config('airport_transfers.maximum_luggage', 100),
            ],
            'service_address' => ['required', 'string', 'max:500'],
            'contact_name' => ['required', 'string', 'max:180'],
            'contact_email' => ['required', 'email:rfc', 'max:255'],
            'contact_phone' => [
                'required',
                'string',
                'max:40',
                'regex:/\A\+?[0-9][0-9\s().-]{6,39}\z/',
            ],
            'special_requests' => ['nullable', 'string', 'max:5000'],
            'acknowledge_request' => ['required', 'accepted'],
            'idempotency_key' => ['required', 'uuid'],
        ])->validate();

        $validated['transfer_type'] = $validated['transfer_type'] instanceof AirportTransferType
            ? $validated['transfer_type']
            : AirportTransferType::from($validated['transfer_type']);
        $validated['vehicle_type'] = strtolower(trim($validated['vehicle_type']));
        $validated['currency'] = $this->currency($validated['currency']);
        $validated['flight_number'] = $this->normalizedFlightNumber($validated['flight_number'] ?? null);
        $validated['flight_scheduled_at'] = $this->utcDateTime(
            $validated['flight_scheduled_at'],
            'flight_scheduled_at',
        );

        if ($validated['transfer_type'] === AirportTransferType::Pickup) {
            $validated['service_starts_at'] = $validated['flight_scheduled_at'];
        } else {
            if (! filled($validated['service_starts_at'] ?? null)) {
                $this->invalid('service_starts_at', 'Enter the address pickup time for an airport drop-off.');
            }

            $validated['service_starts_at'] = $this->utcDateTime(
                $validated['service_starts_at'],
                'service_starts_at',
            );

            if (! $validated['flight_scheduled_at']->isAfter($validated['service_starts_at'])) {
                $this->invalid('flight_scheduled_at', 'The scheduled flight must be after address pickup.');
            }
        }

        $now = now()->toImmutable();
        $minimumStart = $now->addHours((int) config('airport_transfers.minimum_notice_hours', 2));
        $maximumStart = $now->addDays((int) config('airport_transfers.maximum_advance_days', 365));

        if ($validated['service_starts_at']->isBefore($minimumStart)) {
            $this->invalid('service_starts_at', 'Select a pickup time that meets the minimum notice period.');
        }

        if ($validated['service_starts_at']->isAfter($maximumStart)) {
            $this->invalid('service_starts_at', 'The pickup time is too far in advance.');
        }

        $validated['passenger_count'] = (int) $validated['passenger_count'];
        $validated['luggage_count'] = (int) $validated['luggage_count'];
        $validated['service_address'] = trim($validated['service_address']);
        $validated['contact_name'] = trim($validated['contact_name']);
        $validated['contact_email'] = $this->normalizedEmail($validated['contact_email']);
        $validated['contact_phone'] = $this->normalizedPhone($validated['contact_phone']);
        $validated['special_requests'] = $this->nullableString($validated['special_requests'] ?? null);
        $validated['idempotency_owner_hash'] = $this->idempotencyOwnerHash(
            $customer,
            $validated['contact_email'],
            $validated['contact_phone'],
        );

        try {
            $validated['request_fingerprint'] = $this->requestFingerprint([
                'owner_hash' => $validated['idempotency_owner_hash'],
                'airport_id' => $airport->getKey(),
                'location_id' => $location->getKey(),
                'transfer_type' => $validated['transfer_type']->value,
                'vehicle_type' => $validated['vehicle_type'],
                'currency' => $validated['currency'],
                'service_starts_at' => $validated['service_starts_at']->toIso8601String(),
                'flight_number' => $validated['flight_number'],
                'flight_scheduled_at' => $validated['flight_scheduled_at']->toIso8601String(),
                'passenger_count' => $validated['passenger_count'],
                'luggage_count' => $validated['luggage_count'],
                'service_address' => $validated['service_address'],
                'contact_name' => $validated['contact_name'],
                'contact_email' => $validated['contact_email'],
                'contact_phone' => $validated['contact_phone'],
                'special_requests' => $validated['special_requests'],
            ]);
        } catch (JsonException) {
            $this->invalid('idempotency_key', 'The transfer request could not be normalized.');
        }

        return $validated;
    }

    private function normalizedFlightNumber(mixed $value): ?string
    {
        $value = $this->nullableString($value);

        if ($value === null) {
            return null;
        }

        return strtoupper((string) preg_replace('/\s+/', ' ', $value));
    }

    private function dispatchReceivedNotification(AirportTransferBooking $booking): void
    {
        $booking->loadMissing('customer');
        $notification = new AirportTransferBookingReceivedNotification(
            recipientName: $booking->contact_name,
            bookingReference: $booking->reference,
            transferLabel: $booking->transfer_type->label(),
            airportName: $booking->airport_name_snapshot,
            locationName: $booking->location_name_snapshot,
            serviceStartsAt: $booking->service_starts_at->toIso8601String(),
            amountMinor: $booking->amount_minor,
            currency: $booking->currency,
            viewUrl: $this->bookingViewUrl($booking),
        );

        $this->notifyBookingRecipient($booking, $notification);
    }
}
