<?php

namespace Database\Factories;

use App\Models\Vehicle;
use App\Models\VehicleMedia;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<VehicleMedia> */
class VehicleMediaFactory extends Factory
{
    public function definition(): array
    {
        return [
            'vehicle_id' => Vehicle::factory(),
            'url' => 'https://images.example.test/vehicles/'.Str::uuid().'.jpg',
            'alt_text' => fake()->sentence(5),
            'caption' => fake()->optional()->sentence(),
            'is_cover' => false,
            'sort_order' => fake()->numberBetween(1, 20),
        ];
    }

    public function cover(): static
    {
        return $this->state(fn (): array => [
            'is_cover' => true,
            'sort_order' => 0,
        ]);
    }
}
