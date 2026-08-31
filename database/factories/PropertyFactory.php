<?php

namespace Database\Factories;

use App\Enums\PropertyStatus;
use App\Enums\PropertyType;
use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Property> */
class PropertyFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->randomElement(['Kazinga', 'Nile View', 'Rwenzori', 'Bwindi Forest', 'Ssese'])
            .' '.fake()->randomElement(['Lodge', 'Hotel', 'Cottages', 'Retreat']);

        return [
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'name' => $name,
            'property_type' => fake()->randomElement(PropertyType::cases()),
            'region' => fake()->randomElement(['Western Uganda', 'Central Uganda', 'Eastern Uganda', 'Northern Uganda']),
            'district' => fake()->randomElement(['Kasese', 'Kampala', 'Jinja', 'Gulu', 'Kabale']),
            'address' => fake()->streetAddress(),
            'summary' => 'A quiet place to stay within reach of the park gate, with hot water and a proper breakfast.',
            'description' => implode("\n\n", fake()->paragraphs(4)),
            'check_in_from' => '14:00:00',
            'check_out_by' => '10:00:00',
            'cancellation_cutoff_hours' => 48,
            'status' => PropertyStatus::Draft,
            'is_featured' => false,
        ];
    }

    public function published(?string $at = null): static
    {
        return $this->state(fn (): array => [
            'status' => PropertyStatus::Published,
            'published_at' => $at ?? now()->subDay(),
        ]);
    }

    /** Published with a date that has not arrived — must never be public. */
    public function scheduled(): static
    {
        return $this->state(fn (): array => [
            'status' => PropertyStatus::Published,
            'published_at' => now()->addDay(),
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn (): array => ['status' => PropertyStatus::Archived]);
    }

    public function featured(): static
    {
        return $this->state(fn (): array => ['is_featured' => true]);
    }

    public function inRegion(string $region): static
    {
        return $this->state(fn (): array => ['region' => $region]);
    }

    public function cancellableUpTo(int $hours): static
    {
        return $this->state(fn (): array => ['cancellation_cutoff_hours' => $hours]);
    }
}
