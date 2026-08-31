<?php

namespace Database\Factories;

use App\Models\Vehicle;
use App\Models\VehicleHireRate;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<VehicleHireRate> */
class VehicleHireRateFactory extends Factory
{
    public function definition(): array
    {
        return [
            'vehicle_id' => Vehicle::factory()->published(),
            'currency' => 'UGX',
            'self_drive_daily_minor' => fake()->numberBetween(120_000, 600_000),
            'with_driver_daily_minor' => fake()->numberBetween(220_000, 800_000),
            'security_deposit_minor' => fake()->numberBetween(100_000, 500_000),
            'effective_from' => now()->subMonth(),
            'effective_until' => null,
            'is_active' => true,
            'created_by_user_id' => null,
        ];
    }

    public function selfDriveOnly(): static
    {
        return $this->state(fn (): array => [
            'with_driver_daily_minor' => null,
        ]);
    }

    public function withDriverOnly(): static
    {
        return $this->state(fn (): array => [
            'self_drive_daily_minor' => null,
        ]);
    }

    public function inCurrency(string $currency): static
    {
        return $this->state(fn (): array => ['currency' => strtoupper($currency)]);
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
