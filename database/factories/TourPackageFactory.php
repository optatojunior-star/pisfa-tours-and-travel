<?php

namespace Database\Factories;

use App\Enums\TourPackageStatus;
use App\Models\TourCategory;
use App\Models\TourPackage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<TourPackage> */
class TourPackageFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->words(3, true);

        return [
            'tour_category_id' => TourCategory::factory(),
            'name' => Str::title($name),
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(100, 99999),
            'destination' => fake()->city(),
            'summary' => fake()->sentence(12),
            'description' => fake()->paragraphs(3, true),
            'status' => TourPackageStatus::Draft,
            'published_at' => null,
            'is_featured' => false,
            'duration_days' => fake()->numberBetween(1, 14),
            'base_price_minor' => fake()->numberBetween(50_000, 8_000_000),
            'currency' => 'UGX',
            'min_travelers' => 1,
            'max_travelers' => fake()->numberBetween(4, 24),
            'cancellation_cutoff_hours' => 24,
            'created_by_user_id' => null,
            'updated_by_user_id' => null,
        ];
    }

    public function published(): static
    {
        return $this->state(fn (): array => [
            'status' => TourPackageStatus::Published,
            'published_at' => now()->subDay(),
        ]);
    }

    public function scheduledForPublication(): static
    {
        return $this->state(fn (): array => [
            'status' => TourPackageStatus::Published,
            'published_at' => now()->addDay(),
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn (): array => [
            'status' => TourPackageStatus::Archived,
            'published_at' => now()->subMonth(),
        ]);
    }

    public function featured(): static
    {
        return $this->published()->state(fn (): array => ['is_featured' => true]);
    }
}
