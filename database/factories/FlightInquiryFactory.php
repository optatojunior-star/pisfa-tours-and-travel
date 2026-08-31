<?php

namespace Database\Factories;

use App\Enums\FlightInquiryScope;
use App\Enums\FlightInquiryStatus;
use App\Enums\FlightTravelClass;
use App\Enums\FlightTripType;
use App\Models\FlightInquiry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<FlightInquiry> */
class FlightInquiryFactory extends Factory
{
    public function definition(): array
    {
        $email = fake()->unique()->safeEmail();
        $phone = '+256700'.fake()->unique()->numerify('######');
        $outbound = CarbonImmutable::now(config('pisfa.business_timezone', 'Africa/Kampala'))
            ->startOfDay()
            ->addDays(fake()->numberBetween(7, 90));

        return [
            'reference' => 'FLT-'.Str::upper((string) Str::ulid()),
            'customer_id' => null,
            'idempotency_owner_hash' => hash('sha256', $email.'|'.$phone.'|'.Str::random(8)),
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', Str::random(32)),
            'status' => FlightInquiryStatus::New,
            'scope' => FlightInquiryScope::International,
            'trip_type' => FlightTripType::Return,
            'travel_class' => FlightTravelClass::Economy,
            'origin' => 'Entebbe',
            'destination' => fake()->randomElement(['Nairobi', 'Dubai', 'Johannesburg', 'Istanbul']),
            'outbound_on' => $outbound->toDateString(),
            'return_on' => $outbound->addDays(fake()->numberBetween(3, 21))->toDateString(),
            'passenger_count' => fake()->numberBetween(1, 4),
            'contact_name' => fake()->name(),
            'contact_email' => $email,
            'contact_phone' => $phone,
            'notes' => fake()->optional()->sentence(12),
            'assigned_to_user_id' => null,
            'assigned_at' => null,
            'acknowledged_at' => now(),
            'reopen_count' => 0,
        ];
    }

    public function domestic(): static
    {
        return $this->state(fn (): array => [
            'scope' => FlightInquiryScope::Domestic,
            'origin' => 'Entebbe',
            'destination' => fake()->randomElement(['Kihihi', 'Kasese', 'Pakuba', 'Arua']),
        ]);
    }

    public function oneWay(): static
    {
        return $this->state(fn (): array => [
            'trip_type' => FlightTripType::OneWay,
            'return_on' => null,
        ]);
    }

    public function forCustomer(User $customer): static
    {
        return $this->state(fn (): array => [
            'customer_id' => $customer->getKey(),
            'contact_name' => $customer->name,
            'contact_email' => $customer->email,
            'contact_phone' => $customer->phone ?? '+256700000000',
        ]);
    }

    public function withStatus(FlightInquiryStatus $status): static
    {
        return $this->state(fn (): array => ['status' => $status]);
    }
}
