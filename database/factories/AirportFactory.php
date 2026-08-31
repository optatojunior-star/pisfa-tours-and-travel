<?php

namespace Database\Factories;

use App\Models\Airport;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Airport> */
class AirportFactory extends Factory
{
    public function definition(): array
    {
        $city = fake()->city();

        return [
            'code' => Str::upper(fake()->unique()->lexify('???')),
            'name' => $city.' International Airport',
            'city' => $city,
            'country_code' => 'UG',
            'timezone' => 'Africa/Kampala',
            'terminal_information' => fake()->optional()->sentence(),
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

    public function withCode(string $code): static
    {
        return $this->state(fn (): array => ['code' => strtoupper(trim($code))]);
    }
}
