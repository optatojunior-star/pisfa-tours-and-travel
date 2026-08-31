<?php

namespace Database\Factories;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Models\TourAssignment;
use App\Models\TourBooking;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TourAssignment> */
class TourAssignmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tour_booking_id' => TourBooking::factory(),
            'driver_user_id' => User::factory()->state([
                'role' => UserRole::Driver,
                'status' => AccountStatus::Active,
            ]),
            'assigned_by_user_id' => User::factory()->state([
                'role' => UserRole::Staff,
                'status' => AccountStatus::Active,
            ]),
            'starts_at' => fn (array $attributes) => TourBooking::query()
                ->findOrFail($attributes['tour_booking_id'])->departure_starts_at_snapshot,
            'ends_at' => fn (array $attributes) => TourBooking::query()
                ->findOrFail($attributes['tour_booking_id'])->departure_ends_at_snapshot,
            'assigned_at' => now(),
            'unassigned_at' => null,
            'unassigned_by_user_id' => null,
            'unassignment_reason' => null,
        ];
    }

    public function unassigned(): static
    {
        return $this->state(fn (): array => [
            'unassigned_at' => now(),
            'unassigned_by_user_id' => User::factory()->state([
                'role' => UserRole::Staff,
                'status' => AccountStatus::Active,
            ]),
            'unassignment_reason' => 'Operational reassignment.',
        ]);
    }
}
