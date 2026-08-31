<?php

namespace Database\Factories;

use App\Models\TourItineraryDay;
use App\Models\TourPackage;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TourItineraryDay> */
class TourItineraryDayFactory extends Factory
{
    public function definition(): array
    {
        $dayNumber = fake()->numberBetween(1, 14);

        return [
            'tour_package_id' => TourPackage::factory(),
            'day_number' => $dayNumber,
            'title' => fake()->sentence(4),
            'description' => fake()->paragraph(),
            'activities' => fake()->sentences(3),
            'meals' => fake()->randomElement(['Breakfast', 'Breakfast and lunch', 'All meals', null]),
            'overnight_location' => fake()->optional()->city(),
            'sort_order' => $dayNumber,
        ];
    }
}
