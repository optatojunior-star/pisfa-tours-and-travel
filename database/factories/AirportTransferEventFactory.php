<?php

namespace Database\Factories;

use App\Enums\AirportTransferEventType;
use App\Models\AirportTransferBooking;
use App\Models\AirportTransferEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AirportTransferEvent> */
class AirportTransferEventFactory extends Factory
{
    public function definition(): array
    {
        return [
            'airport_transfer_booking_id' => AirportTransferBooking::factory()->confirmed(),
            'event_type' => AirportTransferEventType::PickupReminder,
            'payload' => null,
            'processed_at' => null,
        ];
    }

    public function bookingExpired(): static
    {
        return $this->state(fn (): array => [
            'event_type' => AirportTransferEventType::BookingExpired,
        ]);
    }

    public function loyaltyEligible(): static
    {
        return $this->state(fn (): array => [
            'event_type' => AirportTransferEventType::LoyaltyEligible,
        ]);
    }

    public function processed(): static
    {
        return $this->state(fn (): array => ['processed_at' => now()]);
    }
}
