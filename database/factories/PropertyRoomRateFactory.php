<?php

namespace Database\Factories;

use App\Models\PropertyRoomRate;
use App\Models\PropertyRoomType;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PropertyRoomRate> */
class PropertyRoomRateFactory extends Factory
{
    public function definition(): array
    {
        return [
            'property_room_type_id' => PropertyRoomType::factory(),
            'currency' => 'UGX',
            // UGX has no minor unit, so the integer is whole shillings.
            'nightly_rate_minor' => 250_000,
            'effective_from' => now()->subYear()->toDateString(),
            'effective_until' => null,
            'minimum_nights' => 1,
            'is_active' => true,
        ];
    }

    public function forRoomType(PropertyRoomType $roomType): static
    {
        return $this->state(fn (): array => ['property_room_type_id' => $roomType->getKey()]);
    }

    public function priced(int $minor, string $currency = 'UGX'): static
    {
        return $this->state(fn (): array => [
            'nightly_rate_minor' => $minor,
            'currency' => $currency,
        ]);
    }

    public function season(string $from, ?string $until = null): static
    {
        return $this->state(fn (): array => [
            'effective_from' => $from,
            'effective_until' => $until,
        ]);
    }

    public function minimumNights(int $nights): static
    {
        return $this->state(fn (): array => ['minimum_nights' => $nights]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
