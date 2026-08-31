<?php

namespace Database\Factories;

use App\Models\Property;
use App\Models\PropertyRoomType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<PropertyRoomType> */
class PropertyRoomTypeFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->randomElement(['Standard Double', 'Family Cottage', 'Twin Room', 'Safari Tent']);

        return [
            'property_id' => Property::factory(),
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'name' => $name,
            'description' => fake()->sentence(14),
            'quantity' => 3,
            'max_adults' => 2,
            'max_children' => 2,
            'bed_configuration' => 'One king bed',
            'size_sqm' => 28,
            'is_active' => true,
        ];
    }

    public function forProperty(Property $property): static
    {
        return $this->state(fn (): array => ['property_id' => $property->getKey()]);
    }

    public function withQuantity(int $quantity): static
    {
        return $this->state(fn (): array => ['quantity' => $quantity]);
    }

    public function sleeping(int $adults, int $children = 0): static
    {
        return $this->state(fn (): array => [
            'max_adults' => $adults,
            'max_children' => $children,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
