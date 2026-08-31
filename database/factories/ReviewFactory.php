<?php

namespace Database\Factories;

use App\Enums\ReviewStatus;
use App\Models\Review;
use App\Models\TourBooking;
use App\Models\TourPackage;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** @extends Factory<Review> */
class ReviewFactory extends Factory
{
    public function definition(): array
    {
        return [
            'reference' => 'REV-'.Str::upper((string) Str::ulid()),
            'customer_id' => User::factory(),
            // A review always belongs to a real booking and a real subject;
            // both columns are NOT NULL because eligibility depends on them.
            'booking_type' => (new TourBooking)->getMorphClass(),
            'booking_id' => TourBooking::factory(),
            'reviewable_type' => (new TourPackage)->getMorphClass(),
            'reviewable_id' => TourPackage::factory(),
            'rating' => fake()->numberBetween(3, 5),
            'title' => fake()->sentence(5),
            'body' => fake()->paragraph(4),
            'status' => ReviewStatus::Pending,
        ];
    }

    public function forBooking(Model $booking): static
    {
        return $this->state(fn (): array => [
            'booking_type' => $booking->getMorphClass(),
            'booking_id' => $booking->getKey(),
            'customer_id' => $booking->getAttribute('customer_id'),
        ]);
    }

    public function about(Model $subject): static
    {
        return $this->state(fn (): array => [
            'reviewable_type' => $subject->getMorphClass(),
            'reviewable_id' => $subject->getKey(),
        ]);
    }

    public function withStatus(ReviewStatus $status): static
    {
        return $this->state(fn (): array => [
            'status' => $status,
            'published_at' => $status === ReviewStatus::Published ? now() : null,
        ]);
    }

    public function rated(int $rating): static
    {
        return $this->state(fn (): array => ['rating' => $rating]);
    }
}
