<?php

namespace Database\Factories;

use App\Enums\TourBookingStatus;
use App\Models\TourBooking;
use App\Models\TourDeparture;
use App\Models\TourPackage;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<TourBooking> */
class TourBookingFactory extends Factory
{
    public function definition(): array
    {
        $travelerCount = fake()->numberBetween(1, 4);

        return [
            'reference' => 'TOUR-'.Str::upper(Str::random(14)),
            'customer_id' => User::factory()->state([
                'phone' => '+256700'.fake()->unique()->numerify('######'),
            ]),
            'tour_package_id' => TourPackage::factory()->published(),
            'tour_departure_id' => fn (array $attributes): int => TourDeparture::factory()->create([
                'tour_package_id' => $attributes['tour_package_id'],
            ])->getKey(),
            'idempotency_key' => (string) Str::uuid(),
            'status' => TourBookingStatus::Pending,
            'traveler_count' => $travelerCount,
            'package_name_snapshot' => fn (array $attributes): string => TourPackage::query()
                ->findOrFail($attributes['tour_package_id'])->name,
            'destination_snapshot' => fn (array $attributes): ?string => TourPackage::query()
                ->findOrFail($attributes['tour_package_id'])->destination,
            'departure_starts_at_snapshot' => fn (array $attributes) => TourDeparture::query()
                ->findOrFail($attributes['tour_departure_id'])->starts_at,
            'departure_ends_at_snapshot' => fn (array $attributes) => TourDeparture::query()
                ->findOrFail($attributes['tour_departure_id'])->ends_at,
            'cancellation_cutoff_at_snapshot' => fn (array $attributes) => TourDeparture::query()
                ->findOrFail($attributes['tour_departure_id'])->cancellation_cutoff_at,
            'unit_price_minor' => fn (array $attributes): int => TourDeparture::query()
                ->findOrFail($attributes['tour_departure_id'])->effectivePriceMinor(),
            'subtotal_minor' => fn (array $attributes): int => $attributes['unit_price_minor'] * $attributes['traveler_count'],
            'total_minor' => fn (array $attributes): int => $attributes['subtotal_minor'],
            'currency' => fn (array $attributes): string => TourDeparture::query()
                ->findOrFail($attributes['tour_departure_id'])->effectiveCurrency(),
            'contact_name' => fn (array $attributes): string => User::query()
                ->findOrFail($attributes['customer_id'])->name,
            'contact_email' => fn (array $attributes): string => User::query()
                ->findOrFail($attributes['customer_id'])->email,
            'contact_phone' => fn (array $attributes): string => User::query()
                ->findOrFail($attributes['customer_id'])->phone,
            'special_requests' => fake()->optional()->sentence(),
            'internal_notes' => null,
            'cancellation_reason' => null,
            'cancelled_by_user_id' => null,
            'confirmed_at' => null,
            'in_progress_at' => null,
            'completed_at' => null,
            'cancelled_at' => null,
            'assigned_driver_user_id' => null,
        ];
    }

    public function confirmed(): static
    {
        return $this->state(fn (): array => [
            'status' => TourBookingStatus::Confirmed,
            'confirmed_at' => now(),
        ]);
    }

    public function inProgress(): static
    {
        return $this->state(fn (): array => [
            'status' => TourBookingStatus::InProgress,
            'confirmed_at' => now()->subHour(),
            'in_progress_at' => now(),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (): array => [
            'status' => TourBookingStatus::Completed,
            'confirmed_at' => now()->subDays(2),
            'in_progress_at' => now()->subDay(),
            'completed_at' => now(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (): array => [
            'status' => TourBookingStatus::Cancelled,
            'cancelled_at' => now(),
            'cancellation_reason' => 'Travel plans changed.',
        ]);
    }
}
