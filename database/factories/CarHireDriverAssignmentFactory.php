<?php

namespace Database\Factories;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Models\CarHireBooking;
use App\Models\CarHireDriverAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CarHireDriverAssignment> */
class CarHireDriverAssignmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'car_hire_booking_id' => CarHireBooking::factory()->withDriver()->confirmed(),
            'driver_user_id' => User::factory()->state([
                'role' => UserRole::Driver,
                'status' => AccountStatus::Active,
                'email_verified_at' => now(),
            ]),
            'assigned_by_user_id' => User::factory()->state([
                'role' => UserRole::Staff,
                'status' => AccountStatus::Active,
                'email_verified_at' => now(),
            ]),
            'starts_at' => fn (array $attributes) => CarHireBooking::query()
                ->findOrFail($attributes['car_hire_booking_id'])->pickup_at,
            'ends_at' => fn (array $attributes) => CarHireBooking::query()
                ->findOrFail($attributes['car_hire_booking_id'])->return_at,
            'assigned_at' => now(),
            'unassigned_at' => null,
            'unassigned_by_user_id' => null,
            'unassignment_reason' => null,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (CarHireDriverAssignment $assignment): void {
            if ($assignment->isActive()) {
                $assignment->booking()->update([
                    'assigned_driver_user_id' => $assignment->driver_user_id,
                ]);
            }
        });
    }

    public function unassigned(): static
    {
        return $this->state(fn (): array => [
            'unassigned_at' => now(),
            'unassigned_by_user_id' => User::factory()->state([
                'role' => UserRole::Staff,
                'status' => AccountStatus::Active,
                'email_verified_at' => now(),
            ]),
            'unassignment_reason' => 'Operational reassignment.',
        ]);
    }
}
