<?php

namespace Tests\Feature\AirportTransfers\Concerns;

use App\Actions\AirportTransfers\CreateAirportTransferBooking;
use App\Enums\AccountStatus;
use App\Enums\AirportTransferType;
use App\Enums\UserRole;
use App\Models\Airport;
use App\Models\AirportTransferBooking;
use App\Models\AirportTransferLocation;
use App\Models\AirportTransferRate;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

trait BuildsAirportTransferFixtures
{
    /** @param array<string, mixed> $attributes */
    protected function user(UserRole $role, array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'role' => $role,
            'status' => AccountStatus::Active,
            'email_verified_at' => now(),
            'phone' => '+256700'.fake()->unique()->numerify('######'),
            'two_factor_required' => false,
        ], $attributes));
    }

    /** @param array<string, mixed> $attributes */
    protected function customer(array $attributes = []): User
    {
        return $this->user(UserRole::Customer, $attributes);
    }

    /** @param array<string, mixed> $attributes */
    protected function operationsUser(array $attributes = []): User
    {
        return $this->user(UserRole::Staff, $attributes);
    }

    /** @param array<string, mixed> $attributes */
    protected function driver(array $attributes = []): User
    {
        return $this->user(UserRole::Driver, $attributes);
    }

    /**
     * @param  array<string, mixed>  $airportAttributes
     * @param  array<string, mixed>  $locationAttributes
     * @param  array<string, mixed>  $rateAttributes
     * @return array{Airport, AirportTransferLocation, AirportTransferRate}
     */
    protected function bookableRoute(
        array $airportAttributes = [],
        array $locationAttributes = [],
        array $rateAttributes = [],
    ): array {
        $airport = Airport::factory()->create($airportAttributes);
        $location = AirportTransferLocation::factory()->create($locationAttributes);
        $rate = AirportTransferRate::factory()->create(array_merge([
            'airport_id' => $airport->getKey(),
            'airport_transfer_location_id' => $location->getKey(),
            'effective_from' => now()->subDay(),
            'effective_until' => null,
            'is_active' => true,
        ], $rateAttributes));

        return [$airport, $location, $rate];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function bookingPayload(
        ?User $customer = null,
        AirportTransferType $type = AirportTransferType::Pickup,
        array $overrides = [],
    ): array {
        $serviceStartsAt = now()->toImmutable()
            ->setTimezone((string) config('pisfa.business_timezone', 'Africa/Kampala'))
            ->addDays(10)
            ->startOfHour();
        $flightScheduledAt = $type === AirportTransferType::Pickup
            ? $serviceStartsAt
            : $serviceStartsAt->addHours(4);

        return array_merge([
            'transfer_type' => $type->value,
            'vehicle_type' => 'sedan',
            'currency' => 'UGX',
            'service_starts_at' => $serviceStartsAt->format('Y-m-d H:i:s'),
            'flight_number' => 'KQ 101',
            'flight_scheduled_at' => $flightScheduledAt->format('Y-m-d H:i:s'),
            'passenger_count' => 2,
            'luggage_count' => 1,
            'service_address' => 'Plot 10 Kampala Road, Kampala',
            // `??` already covers a null customer, so the nullsafe operator
            // would only be noise here.
            'contact_name' => $customer->name ?? 'Guest Traveller',
            'contact_email' => $customer->email ?? 'guest@example.test',
            'contact_phone' => $customer->phone ?? '+256701234567',
            'special_requests' => null,
            'acknowledge_request' => '1',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function createBooking(
        ?User $customer,
        Airport $airport,
        AirportTransferLocation $location,
        AirportTransferType $type = AirportTransferType::Pickup,
        array $overrides = [],
        ?string $idempotencyKey = null,
    ): AirportTransferBooking {
        return app(CreateAirportTransferBooking::class)->execute(
            $customer,
            $airport,
            $location,
            $this->bookingPayload($customer, $type, $overrides),
            $idempotencyKey ?? (string) Str::uuid(),
        );
    }

    protected function localDateTime(CarbonImmutable $dateTime): string
    {
        return $dateTime
            ->setTimezone((string) config('pisfa.business_timezone', 'Africa/Kampala'))
            ->format('Y-m-d H:i:s');
    }
}
