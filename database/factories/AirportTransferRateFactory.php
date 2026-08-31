<?php

namespace Database\Factories;

use App\Enums\AirportTransferType;
use App\Models\Airport;
use App\Models\AirportTransferLocation;
use App\Models\AirportTransferRate;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AirportTransferRate> */
class AirportTransferRateFactory extends Factory
{
    public function definition(): array
    {
        return [
            'airport_id' => Airport::factory(),
            'airport_transfer_location_id' => AirportTransferLocation::factory(),
            'transfer_type' => AirportTransferType::Pickup,
            'vehicle_type' => 'sedan',
            'currency' => 'UGX',
            'passenger_capacity' => 4,
            'luggage_capacity' => 4,
            'amount_minor' => fake()->numberBetween(80_000, 500_000),
            'estimated_duration_minutes' => 90,
            'effective_from' => now()->subMonth(),
            'effective_until' => null,
            'is_active' => true,
            'created_by_user_id' => null,
        ];
    }

    public function pickup(): static
    {
        return $this->state(fn (): array => ['transfer_type' => AirportTransferType::Pickup]);
    }

    public function dropoff(): static
    {
        return $this->state(fn (): array => ['transfer_type' => AirportTransferType::Dropoff]);
    }

    public function forVehicleType(
        string $vehicleType,
        int $passengerCapacity = 4,
        int $luggageCapacity = 4,
    ): static {
        return $this->state(fn (): array => [
            'vehicle_type' => strtolower(trim($vehicleType)),
            'passenger_capacity' => $passengerCapacity,
            'luggage_capacity' => $luggageCapacity,
        ]);
    }

    public function inCurrency(string $currency): static
    {
        return $this->state(fn (): array => ['currency' => strtoupper(trim($currency))]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => [
            'effective_from' => now()->subMonths(2),
            'effective_until' => now()->subMonth(),
            'is_active' => false,
        ]);
    }

    public function future(): static
    {
        return $this->state(fn (): array => [
            'effective_from' => now()->addMonth(),
            'effective_until' => null,
            'is_active' => true,
        ]);
    }
}
