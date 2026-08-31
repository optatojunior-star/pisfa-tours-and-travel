<?php

namespace Database\Factories;

use App\Enums\TourTravelerType;
use App\Models\TourBooking;
use App\Models\TourTraveler;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TourTraveler> */
class TourTravelerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tour_booking_id' => TourBooking::factory(),
            'full_name' => fake()->name(),
            'traveler_type' => TourTravelerType::Adult,
            'date_of_birth' => fake()->optional()->dateTimeBetween('-75 years', '-18 years'),
            'nationality' => fake()->optional()->country(),
            'dietary_notes' => fake()->optional()->sentence(),
            'accessibility_notes' => fake()->optional()->sentence(),
            'is_lead' => false,
            'sort_order' => fake()->numberBetween(0, 20),
        ];
    }

    public function lead(): static
    {
        return $this->state(fn (): array => [
            'is_lead' => true,
            'sort_order' => 0,
        ]);
    }

    public function child(): static
    {
        return $this->state(fn (): array => [
            'traveler_type' => TourTravelerType::Child,
            'date_of_birth' => fake()->dateTimeBetween('-17 years', '-2 years'),
        ]);
    }
}
