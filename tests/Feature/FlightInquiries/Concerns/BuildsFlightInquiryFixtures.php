<?php

namespace Tests\Feature\FlightInquiries\Concerns;

use App\Actions\FlightInquiries\CreateFlightInquiry;
use App\Enums\AccountStatus;
use App\Enums\FlightInquiryScope;
use App\Enums\FlightTravelClass;
use App\Enums\FlightTripType;
use App\Enums\UserRole;
use App\Models\FlightInquiry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

trait BuildsFlightInquiryFixtures
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
    protected function manager(array $attributes = []): User
    {
        return $this->user(UserRole::Manager, array_merge(['two_factor_required' => false], $attributes));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function inquiryPayload(?User $customer = null, array $overrides = []): array
    {
        $outbound = CarbonImmutable::now((string) config('pisfa.business_timezone', 'Africa/Kampala'))
            ->startOfDay()
            ->addDays(30);

        return array_merge([
            'idempotency_key' => (string) Str::uuid(),
            'scope' => FlightInquiryScope::International->value,
            'trip_type' => FlightTripType::Return->value,
            'travel_class' => FlightTravelClass::Economy->value,
            'origin' => 'Entebbe',
            'destination' => 'Nairobi',
            'outbound_on' => $outbound->toDateString(),
            'return_on' => $outbound->addDays(7)->toDateString(),
            'passenger_count' => 2,
            'contact_name' => $customer?->name ?? 'Guest Traveller',
            'contact_email' => $customer?->email ?? 'guest@example.test',
            'contact_phone' => $customer?->phone ?? '+256701234567',
            'notes' => null,
            'acknowledge_enquiry' => '1',
        ], $overrides);
    }

    /** @param array<string, mixed> $overrides */
    protected function createInquiry(?User $customer = null, array $overrides = []): FlightInquiry
    {
        $payload = $this->inquiryPayload($customer, $overrides);

        return app(CreateFlightInquiry::class)->execute(
            $customer,
            $payload,
            $payload['idempotency_key'],
        );
    }
}
