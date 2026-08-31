<?php

namespace Database\Factories;

use App\Enums\FlightInquiryEntryType;
use App\Models\FlightInquiry;
use App\Models\FlightInquiryEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FlightInquiryEntry> */
class FlightInquiryEntryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'flight_inquiry_id' => FlightInquiry::factory(),
            'author_user_id' => null,
            'entry_type' => FlightInquiryEntryType::InternalNote,
            'body' => fake()->sentence(14),
            'payload' => null,
        ];
    }

    public function ofType(FlightInquiryEntryType $type): static
    {
        return $this->state(fn (): array => ['entry_type' => $type]);
    }
}
