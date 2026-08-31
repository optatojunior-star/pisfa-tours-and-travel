<?php

namespace Database\Factories;

use App\Enums\TourBookingEventType;
use App\Models\TourBooking;
use App\Models\TourBookingEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TourBookingEvent> */
class TourBookingEventFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tour_booking_id' => TourBooking::factory(),
            'event_type' => fake()->randomElement(TourBookingEventType::cases()),
            'payload' => null,
            'processed_at' => null,
        ];
    }

    public function processed(): static
    {
        return $this->state(fn (): array => ['processed_at' => now()]);
    }
}
