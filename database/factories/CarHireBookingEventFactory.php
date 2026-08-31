<?php

namespace Database\Factories;

use App\Enums\CarHireBookingEventType;
use App\Models\CarHireBooking;
use App\Models\CarHireBookingEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CarHireBookingEvent> */
class CarHireBookingEventFactory extends Factory
{
    public function definition(): array
    {
        return [
            'car_hire_booking_id' => CarHireBooking::factory(),
            'event_type' => CarHireBookingEventType::ReturnReminderSent,
            'payload' => null,
            'processed_at' => null,
        ];
    }

    public function bookingExpired(): static
    {
        return $this->state(fn (): array => [
            'event_type' => CarHireBookingEventType::BookingExpired,
        ]);
    }

    public function loyaltyEligible(): static
    {
        return $this->state(fn (): array => [
            'event_type' => CarHireBookingEventType::LoyaltyEligible,
        ]);
    }

    public function processed(): static
    {
        return $this->state(fn (): array => ['processed_at' => now()]);
    }
}
