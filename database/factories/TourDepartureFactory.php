<?php

namespace Database\Factories;

use App\Enums\TourDepartureStatus;
use App\Models\TourDeparture;
use App\Models\TourPackage;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TourDeparture> */
class TourDepartureFactory extends Factory
{
    public function definition(): array
    {
        $startsAt = now()
            ->toImmutable()
            ->addDays(fake()->numberBetween(7, 180))
            ->setTime(fake()->numberBetween(6, 10), 0);

        return [
            'tour_package_id' => TourPackage::factory()->published(),
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addDays(fake()->numberBetween(1, 7)),
            'cancellation_cutoff_at' => $startsAt->subDay(),
            'capacity' => fake()->numberBetween(4, 30),
            'price_override_minor' => null,
            'currency' => null,
            'status' => TourDepartureStatus::Scheduled,
            'meeting_point' => fake()->streetAddress(),
            'customer_notes' => fake()->optional()->sentence(),
            'internal_notes' => fake()->optional()->sentence(),
        ];
    }

    public function withPriceOverride(?int $minor = null, string $currency = 'UGX'): static
    {
        return $this->state(fn (): array => [
            'price_override_minor' => $minor ?? fake()->numberBetween(50_000, 8_000_000),
            'currency' => strtoupper($currency),
        ]);
    }

    public function closed(): static
    {
        return $this->state(fn (): array => ['status' => TourDepartureStatus::Closed]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (): array => ['status' => TourDepartureStatus::Cancelled]);
    }
}
