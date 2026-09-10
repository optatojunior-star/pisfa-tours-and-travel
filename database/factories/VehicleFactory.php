<?php

namespace Database\Factories;

use App\Enums\VehicleBodyType;
use App\Enums\VehicleCatalogueStatus;
use App\Enums\VehicleCondition;
use App\Enums\VehicleDriveType;
use App\Enums\VehicleFuelType;
use App\Enums\VehicleOperationalStatus;
use App\Enums\VehicleTransmission;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Vehicle> */
class VehicleFactory extends Factory
{
    public function definition(): array
    {
        $make = fake()->randomElement(['Toyota', 'Nissan', 'Mitsubishi', 'Ford', 'Isuzu']);
        $model = fake()->randomElement(['Land Cruiser', 'Patrol', 'Pajero', 'Everest', 'D-Max']);
        $name = $make.' '.$model;

        return [
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(8)),
            'registration_plate' => 'U'.Str::upper(Str::random(2)).' '.fake()->unique()->numerify('###').'X',
            'make' => $make,
            'model' => $model,
            'year' => fake()->numberBetween(2016, (int) now()->format('Y')),
            'color' => fake()->safeColorName(),
            // Storage keys from App\Enums, so a factory-made vehicle is one the
            // admin form would also accept.
            'condition' => fake()->randomElement(VehicleCondition::cases())->value,
            'vehicle_type' => fake()->randomElement([
                VehicleBodyType::Suv, VehicleBodyType::Sedan, VehicleBodyType::Van, VehicleBodyType::Pickup,
            ])->value,
            'fuel_type' => fake()->randomElement([VehicleFuelType::Diesel, VehicleFuelType::Petrol])->value,
            'transmission' => fake()->randomElement([VehicleTransmission::Automatic, VehicleTransmission::Manual])->value,
            'drive_type' => fake()->randomElement(VehicleDriveType::cases())->value,
            'engine_cc' => fake()->randomElement([1500, 1800, 2000, 2500, 2700, 3000, 4200]),
            'seating_capacity' => fake()->numberBetween(4, 8),
            'luggage_capacity' => fake()->numberBetween(2, 8),
            'summary' => fake()->sentence(12),
            'description' => fake()->paragraphs(2, true),
            'catalogue_status' => VehicleCatalogueStatus::Draft,
            'operational_status' => VehicleOperationalStatus::Available,
            'published_at' => null,
            'is_featured' => false,
            'created_by_user_id' => null,
            'updated_by_user_id' => null,
        ];
    }

    public function published(): static
    {
        return $this->state(fn (): array => [
            'catalogue_status' => VehicleCatalogueStatus::Published,
            'operational_status' => VehicleOperationalStatus::Available,
            'published_at' => now()->subDay(),
        ]);
    }

    public function scheduledForPublication(): static
    {
        return $this->state(fn (): array => [
            'catalogue_status' => VehicleCatalogueStatus::Published,
            'published_at' => now()->addDay(),
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn (): array => [
            'catalogue_status' => VehicleCatalogueStatus::Archived,
            'published_at' => now()->subMonth(),
            'is_featured' => false,
        ]);
    }

    public function featured(): static
    {
        return $this->published()->state(fn (): array => ['is_featured' => true]);
    }

    public function underMaintenance(): static
    {
        return $this->state(fn (): array => [
            'operational_status' => VehicleOperationalStatus::Maintenance,
        ]);
    }

    public function unavailable(): static
    {
        return $this->state(fn (): array => [
            'operational_status' => VehicleOperationalStatus::Unavailable,
        ]);
    }

    public function retired(): static
    {
        return $this->archived()->state(fn (): array => [
            'operational_status' => VehicleOperationalStatus::Retired,
        ]);
    }
}
