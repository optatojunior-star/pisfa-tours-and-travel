<?php

namespace Database\Seeders;

use App\Models\TourCategory;
use Illuminate\Database\Seeder;

class TourCategorySeeder extends Seeder
{
    /**
     * Seed the baseline categories without overwriting administrator changes.
     */
    public function run(): void
    {
        $categories = [
            [
                'name' => 'Safaris',
                'slug' => 'safaris',
                'description' => 'Wildlife, national park, and nature-focused journeys.',
                'sort_order' => 10,
            ],
            [
                'name' => 'Cultural Experiences',
                'slug' => 'cultural-experiences',
                'description' => 'Community, heritage, food, and cultural experiences.',
                'sort_order' => 20,
            ],
            [
                'name' => 'Adventure Tours',
                'slug' => 'adventure-tours',
                'description' => 'Active outdoor trips and guided adventure experiences.',
                'sort_order' => 30,
            ],
            [
                'name' => 'City Tours',
                'slug' => 'city-tours',
                'description' => 'Guided city sightseeing, history, and local highlights.',
                'sort_order' => 40,
            ],
            [
                'name' => 'Upcountry Escapes',
                'slug' => 'upcountry-escapes',
                'description' => 'Relaxing getaways and discoveries beyond the city.',
                'sort_order' => 50,
            ],
        ];

        foreach ($categories as $category) {
            TourCategory::query()->firstOrCreate(
                ['slug' => $category['slug']],
                [...$category, 'is_active' => true],
            );
        }
    }
}
