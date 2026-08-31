<?php

namespace Database\Factories;

use App\Enums\TourPackageItemType;
use App\Models\TourPackage;
use App\Models\TourPackageItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TourPackageItem> */
class TourPackageItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tour_package_id' => TourPackage::factory(),
            'item_type' => fake()->randomElement(TourPackageItemType::cases()),
            'content' => fake()->sentence(),
            'sort_order' => fake()->numberBetween(0, 20),
        ];
    }

    public function inclusion(): static
    {
        return $this->state(fn (): array => ['item_type' => TourPackageItemType::Inclusion]);
    }

    public function exclusion(): static
    {
        return $this->state(fn (): array => ['item_type' => TourPackageItemType::Exclusion]);
    }
}
