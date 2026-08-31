<?php

namespace Database\Factories;

use App\Enums\TourTravelerType;
use App\Models\GroupBooking;
use App\Models\GroupTraveler;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<GroupTraveler> */
class GroupTravelerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'group_booking_id' => GroupBooking::factory(),
            'user_id' => null,
            'full_name' => fake()->name(),
            'traveler_type' => TourTravelerType::Adult,
            'contact_phone' => '+2567'.fake()->numerify('########'),
            'nationality' => 'Ugandan',
        ];
    }

    public function on(GroupBooking $booking): static
    {
        return $this->state(fn (): array => ['group_booking_id' => $booking->getKey()]);
    }

    public function child(): static
    {
        return $this->state(fn (): array => ['traveler_type' => TourTravelerType::Child]);
    }

    public function withRequirements(): static
    {
        return $this->state(fn (): array => [
            'dietary_requirements' => 'Vegetarian',
            'accessibility_needs' => 'Ground-floor room',
        ]);
    }
}
