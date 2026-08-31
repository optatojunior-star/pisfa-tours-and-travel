<?php

namespace Database\Factories;

use App\Models\AirportTransferLocation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<AirportTransferLocation> */
class AirportTransferLocationFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->city();

        return [
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(8)),
            'name' => $name,
            'region' => fake()->randomElement(['Central Uganda', 'Kampala', 'Wakiso', 'Eastern Uganda']),
            'description' => fake()->optional()->sentence(12),
            'is_active' => true,
            'sort_order' => 0,
            'created_by_user_id' => null,
            'updated_by_user_id' => null,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
