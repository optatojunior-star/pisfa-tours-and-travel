<?php

namespace Database\Factories;

use App\Models\TourPackage;
use App\Models\TourPackageMedia;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<TourPackageMedia> */
class TourPackageMediaFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tour_package_id' => TourPackage::factory(),
            'url' => 'https://images.example.test/tours/'.Str::uuid().'.jpg',
            'alt_text' => fake()->sentence(5),
            'caption' => fake()->optional()->sentence(),
            'is_cover' => false,
            'sort_order' => fake()->numberBetween(0, 20),
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
