<?php

namespace Database\Factories;

use App\Enums\ListingStatus;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleListing;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<VehicleListing> */
class VehicleListingFactory extends Factory
{
    public function definition(): array
    {
        $make = fake()->randomElement(['Toyota', 'Nissan', 'Mitsubishi', 'Subaru', 'Isuzu']);
        $model = fake()->randomElement(['Land Cruiser', 'Prado', 'Harrier', 'Forester', 'Hilux']);
        $year = fake()->numberBetween(2012, (int) now()->format('Y'));
        $title = $year.' '.$make.' '.$model;

        return [
            'reference' => 'LST-'.Str::upper((string) Str::ulid()),
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(6)),
            'vehicle_id' => null,
            'status' => ListingStatus::Draft,
            'title' => $title,
            'make' => $make,
            'model' => $model,
            'year' => $year,
            'body_type' => 'SUV',
            'fuel_type' => 'Diesel',
            'transmission' => 'Automatic',
            'colour' => fake()->safeColorName(),
            'mileage_km' => fake()->numberBetween(20_000, 180_000),
            'seating_capacity' => 7,
            'condition' => 'Used',
            'description' => implode("\n\n", fake()->paragraphs(3)),
            'internal_notes' => null,
            // UGX has no minor unit, so the integer is whole shillings.
            'asking_price_minor' => fake()->numberBetween(35_000_000, 220_000_000),
            'currency' => 'UGX',
            'is_negotiable' => true,
            'is_featured' => false,
        ];
    }

    public function forVehicle(Vehicle $vehicle): static
    {
        return $this->state(fn (): array => ['vehicle_id' => $vehicle->getKey()]);
    }

    public function createdBy(User $user): static
    {
        return $this->state(fn (): array => ['created_by_user_id' => $user->getKey()]);
    }

    /** Live in the showroom. */
    public function available(): static
    {
        return $this->state(fn (): array => [
            'status' => ListingStatus::Available,
            'listed_at' => now()->subDays(3),
        ]);
    }

    public function reserved(): static
    {
        return $this->state(fn (): array => [
            'status' => ListingStatus::Reserved,
            'listed_at' => now()->subDays(10),
            'reserved_at' => now()->subDay(),
        ]);
    }

    public function sold(?int $priceMinor = null, ?string $at = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ListingStatus::Sold,
            'listed_at' => now()->subDays(30),
            'sold_at' => $at ?? now()->subDay(),
            'sold_price_minor' => $priceMinor ?? (int) $attributes['asking_price_minor'],
        ]);
    }

    public function withdrawn(): static
    {
        return $this->state(fn (): array => [
            'status' => ListingStatus::Withdrawn,
            'withdrawn_at' => now()->subDay(),
            'closure_reason' => 'Taken back into the hire fleet.',
        ]);
    }

    public function featured(): static
    {
        return $this->state(fn (): array => ['is_featured' => true]);
    }

    public function priced(int $minor, string $currency = 'UGX'): static
    {
        return $this->state(fn (): array => [
            'asking_price_minor' => $minor,
            'currency' => $currency,
        ]);
    }
}
