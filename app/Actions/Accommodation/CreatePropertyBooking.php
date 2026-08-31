<?php

namespace App\Actions\Accommodation;

use App\Enums\AccountStatus;
use App\Enums\PropertyBookingStatus;
use App\Enums\UserRole;
use App\Models\Property;
use App\Models\PropertyBooking;
use App\Models\PropertyRoomType;
use App\Models\User;
use App\Notifications\Accommodation\PropertyBookingReceivedNotification;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use JsonException;

/**
 * Books rooms for a run of nights.
 *
 * The availability check is a count, not an overlap test. A room type is N
 * interchangeable rooms, so the question is how many are already committed on
 * the busiest night of the requested stay — and it is asked again inside the
 * transaction, under a lock on the room type, because a screen rendered a
 * minute ago is not evidence that a room is still free.
 */
class CreatePropertyBooking
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /** @param array<string, mixed> $attributes */
    public function execute(
        User $customer,
        Property $property,
        PropertyRoomType $roomType,
        array $attributes,
        string $idempotencyKey,
    ): PropertyBooking {
        $input = $this->validatedInput($attributes, $idempotencyKey);

        return DB::transaction(function () use ($customer, $property, $roomType, $input): PropertyBooking {
            $lockedCustomer = User::query()->whereKey($customer->getKey())->lockForUpdate()->first();

            if ($lockedCustomer === null
                || $lockedCustomer->status !== AccountStatus::Active
                || ! $lockedCustomer->hasRole(UserRole::Customer)
                || $lockedCustomer->email_verified_at === null) {
                throw new AuthorizationException;
            }

            $existing = PropertyBooking::query()
                ->where('customer_id', $lockedCustomer->getKey())
                ->where('idempotency_key', $input['idempotency_key'])
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                // A replayed key that describes a different stay is a bug in the
                // caller, not a duplicate submit, and must not silently return
                // somebody else's booking.
                if (! hash_equals($existing->request_fingerprint, $input['request_fingerprint'])) {
                    throw ValidationException::withMessages([
                        'idempotency_key' => 'This idempotency key was already used for a different stay.',
                    ]);
                }

                return $existing->load(['property', 'roomType']);
            }

            // Property before room type, and both before any booking row: one
            // fixed order across the domain, so concurrent bookings queue rather
            // than deadlock.
            $lockedProperty = Property::query()
                ->whereKey($property->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $lockedType = PropertyRoomType::query()
                ->whereKey($roomType->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $lockedType->property_id !== (int) $lockedProperty->getKey()) {
                throw ValidationException::withMessages([
                    'room_type' => 'That room is not part of this property.',
                ]);
            }

            if (! $lockedProperty->isPublishedAt()) {
                throw ValidationException::withMessages([
                    'property' => 'This property is not open for booking.',
                ]);
            }

            if (! $lockedType->is_active) {
                throw ValidationException::withMessages([
                    'room_type' => 'This room is not available to book.',
                ]);
            }

            $this->assertOccupancyFits($lockedType, $input);

            $rate = $lockedType->rateFor($input['check_in_date'], $input['check_out_date'], $input['currency']);

            if ($rate === null) {
                throw ValidationException::withMessages([
                    'check_in_date' => 'These dates are not priced in '.$input['currency']
                        .'. Stays that cross two seasons have to be quoted by hand — please ask us.',
                ]);
            }

            if ($input['nights'] < $rate->minimum_nights) {
                throw ValidationException::withMessages([
                    'check_out_date' => 'This room has a minimum stay of '.$rate->minimum_nights
                        .' '.str('night')->plural($rate->minimum_nights).'.',
                ]);
            }

            // Re-checked under the lock. Everything before this point was a
            // rendered screen; this is the only answer that binds.
            $available = $lockedType->availableRooms($input['check_in_date'], $input['check_out_date']);

            if ($available < $input['rooms']) {
                throw ValidationException::withMessages([
                    'rooms' => $available === 0
                        ? 'This room is fully booked for those dates.'
                        : 'Only '.$available.' '.str('room')->plural($available)
                            .' of this type are left for those dates.',
                ]);
            }

            $total = $this->total($rate->nightly_rate_minor, $input['nights'], $input['rooms']);

            $checkInAt = $this->checkInMoment($lockedProperty, $input['check_in_date']);

            $holdExpiresAt = CarbonImmutable::instance(now())
                ->addMinutes((int) config('accommodation.pending_hold_minutes', 1440));

            // A hold must never outlive the arrival it is holding.
            if ($holdExpiresAt->isAfter($checkInAt)) {
                $holdExpiresAt = $checkInAt;
            }

            $booking = new PropertyBooking;
            $booking->forceFill([
                'reference' => 'STAY-'.Str::upper((string) Str::ulid()),
                'customer_id' => $lockedCustomer->getKey(),
                'property_id' => $lockedProperty->getKey(),
                'property_room_type_id' => $lockedType->getKey(),
                'property_room_rate_id' => $rate->getKey(),
                'idempotency_key' => $input['idempotency_key'],
                'request_fingerprint' => $input['request_fingerprint'],
                'status' => PropertyBookingStatus::Pending,
                'check_in_date' => $input['check_in_date']->toDateString(),
                'check_out_date' => $input['check_out_date']->toDateString(),
                'nights' => $input['nights'],
                'rooms' => $input['rooms'],
                'adults' => $input['adults'],
                'children' => $input['children'],
                'property_name_snapshot' => $lockedProperty->name,
                'room_type_name_snapshot' => $lockedType->name,
                'check_in_from_snapshot' => $lockedProperty->getRawOriginal('check_in_from'),
                'check_out_by_snapshot' => $lockedProperty->getRawOriginal('check_out_by'),
                'nightly_rate_minor' => $rate->nightly_rate_minor,
                'total_minor' => $total,
                'currency' => $rate->currency,
                'contact_name' => $lockedCustomer->name,
                'contact_email' => mb_strtolower($lockedCustomer->email),
                'contact_phone' => $input['contact_phone'],
                'special_requests' => $input['special_requests'],
                'hold_expires_at' => $holdExpiresAt,
                'cancellation_cutoff_at' => $checkInAt->subHours($lockedProperty->cancellation_cutoff_hours),
            ])->save();

            $this->auditLogger->record(
                event: 'property_booking.created',
                auditable: $booking,
                newValues: [
                    'reference' => $booking->reference,
                    'property_id' => $lockedProperty->getKey(),
                    'property_room_type_id' => $lockedType->getKey(),
                    'property_room_rate_id' => $rate->getKey(),
                    'check_in_date' => $booking->check_in_date->toDateString(),
                    'check_out_date' => $booking->check_out_date->toDateString(),
                    'nights' => $booking->nights,
                    'rooms' => $booking->rooms,
                    'nightly_rate_minor' => $booking->nightly_rate_minor,
                    'total_minor' => $booking->total_minor,
                    'currency' => $booking->currency,
                    'hold_expires_at' => $holdExpiresAt->toIso8601String(),
                ],
                user: $lockedCustomer,
            );

            DB::afterCommit(function () use ($lockedCustomer, $booking): void {
                $lockedCustomer->notify(new PropertyBookingReceivedNotification(
                    bookingReference: $booking->reference,
                    propertyName: $booking->property_name_snapshot,
                    roomTypeName: $booking->room_type_name_snapshot,
                    stay: $booking->stayLabel(),
                    total: $booking->formattedTotal(),
                ));
            });

            return $booking->load(['property', 'roomType']);
        }, 3);
    }

    /**
     * Guests must fit in the rooms booked.
     *
     * Checked against the total across rooms rather than per room, because a
     * family of five in two doubles is fine and the desk should not have to
     * split it into two bookings.
     *
     * @param  array<string, mixed>  $input
     */
    private function assertOccupancyFits(PropertyRoomType $roomType, array $input): void
    {
        $rooms = (int) $input['rooms'];

        if ($input['adults'] > $roomType->max_adults * $rooms) {
            throw ValidationException::withMessages([
                'adults' => 'That is more adults than these rooms sleep. Add another room, or choose a larger one.',
            ]);
        }

        if ($input['children'] > $roomType->max_children * $rooms) {
            throw ValidationException::withMessages([
                'children' => $roomType->max_children === 0
                    ? 'This room does not take children. Please choose another.'
                    : 'That is more children than these rooms sleep.',
            ]);
        }
    }

    /** Overflow-checked, so an absurd stay cannot wrap into a small total. */
    private function total(int $nightlyMinor, int $nights, int $rooms): int
    {
        $roomNights = $nights * $rooms;

        if ($nightlyMinor > intdiv(PHP_INT_MAX, max(1, $roomNights))) {
            throw ValidationException::withMessages([
                'check_out_date' => 'That stay is too long to price.',
            ]);
        }

        return $nightlyMinor * $roomNights;
    }

    /** Arrival as a moment, from the property's own local check-in time. */
    private function checkInMoment(Property $property, CarbonImmutable $checkInDate): CarbonImmutable
    {
        $timezone = (string) config('pisfa.business_timezone', 'Africa/Kampala');

        return CarbonImmutable::parse(
            $checkInDate->toDateString().' '.$property->getRawOriginal('check_in_from'),
            $timezone,
        )->setTimezone(config('app.timezone', 'UTC'));
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function validatedInput(array $attributes, string $idempotencyKey): array
    {
        $attributes['idempotency_key'] = trim($idempotencyKey);

        $validated = Validator::make($attributes, [
            'check_in_date' => ['required', 'date'],
            'check_out_date' => ['required', 'date', 'after:check_in_date'],
            'rooms' => ['required', 'integer', 'min:1', 'max:20'],
            'adults' => ['required', 'integer', 'min:1', 'max:60'],
            'children' => ['nullable', 'integer', 'min:0', 'max:60'],
            'currency' => ['required', Rule::in(config('pisfa.currency.supported', ['UGX', 'USD']))],
            'contact_phone' => ['required', 'string', 'max:40', 'regex:/\A\+?[0-9][0-9\s().-]{6,39}\z/'],
            'special_requests' => ['nullable', 'string', 'max:5000'],
            'acknowledge_request' => ['required', 'accepted'],
            'idempotency_key' => ['required', 'uuid'],
        ])->validate();

        $checkIn = CarbonImmutable::parse((string) $validated['check_in_date'])->startOfDay();
        $checkOut = CarbonImmutable::parse((string) $validated['check_out_date'])->startOfDay();

        // Business dates, so "today" is today in Kampala rather than in UTC.
        $today = CarbonImmutable::now((string) config('pisfa.business_timezone', 'Africa/Kampala'))->startOfDay();

        if ($checkIn->isBefore($today)) {
            throw ValidationException::withMessages([
                'check_in_date' => 'Arrival cannot be in the past.',
            ]);
        }

        $nights = (int) $checkIn->diffInDays($checkOut);

        $maxNights = (int) config('accommodation.max_nights', 60);

        if ($nights > $maxNights) {
            throw ValidationException::withMessages([
                'check_out_date' => 'Stays longer than '.$maxNights.' nights are arranged by quotation. Please ask us.',
            ]);
        }

        $fingerprintSource = [
            'check_in_date' => $checkIn->toDateString(),
            'check_out_date' => $checkOut->toDateString(),
            'rooms' => (int) $validated['rooms'],
            'adults' => (int) $validated['adults'],
            'children' => (int) ($validated['children'] ?? 0),
            'currency' => strtoupper((string) $validated['currency']),
        ];

        try {
            $encoded = json_encode($fingerprintSource, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw ValidationException::withMessages([
                'check_in_date' => 'That request could not be read. Please try again.',
            ]);
        }

        return [
            'check_in_date' => $checkIn,
            'check_out_date' => $checkOut,
            'nights' => $nights,
            'rooms' => (int) $validated['rooms'],
            'adults' => (int) $validated['adults'],
            'children' => (int) ($validated['children'] ?? 0),
            'currency' => strtoupper((string) $validated['currency']),
            'contact_phone' => preg_replace('/[\s().-]+/', '', trim((string) $validated['contact_phone'])) ?? '',
            'special_requests' => filled($validated['special_requests'] ?? null)
                ? trim((string) $validated['special_requests'])
                : null,
            'idempotency_key' => (string) $validated['idempotency_key'],
            'request_fingerprint' => hash('sha256', $encoded),
        ];
    }
}
