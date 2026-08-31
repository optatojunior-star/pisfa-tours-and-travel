<?php

namespace Database\Factories;

use App\Models\Vehicle;
use App\Models\VehicleFuelLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<VehicleFuelLog> */
class VehicleFuelLogFactory extends Factory
{
    public function definition(): array
    {
        return [
            'vehicle_id' => Vehicle::factory(),
            'filled_at' => now(),
            'odometer_km' => 10_000,
            'volume_ml' => 50_000,
            'cost_minor' => 250_000,
            'currency' => 'UGX',
            'is_full_tank' => true,
        ];
    }

    public function forVehicle(Vehicle $vehicle): static
    {
        return $this->state(fn (): array => ['vehicle_id' => $vehicle->getKey()]);
    }

    public function at(int $odometerKm, int $volumeMl, int $costMinor = 250_000): static
    {
        return $this->state(fn (): array => [
            'odometer_km' => $odometerKm,
            'volume_ml' => $volumeMl,
            'cost_minor' => $costMinor,
        ]);
    }

    public function partialFill(): static
    {
        return $this->state(fn (): array => ['is_full_tank' => false]);
    }
}
