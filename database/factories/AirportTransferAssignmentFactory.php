<?php

namespace Database\Factories;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Models\AirportTransferAssignment;
use App\Models\AirportTransferBooking;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AirportTransferAssignment> */
class AirportTransferAssignmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'airport_transfer_booking_id' => AirportTransferBooking::factory()->confirmed(),
            'vehicle_id' => Vehicle::factory()->published()->state([
                'vehicle_type' => 'sedan',
                'seating_capacity' => 4,
                'luggage_capacity' => 4,
            ]),
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
            'starts_at' => fn (array $attributes) => AirportTransferBooking::query()
                ->findOrFail($attributes['airport_transfer_booking_id'])->service_starts_at,
            'ends_at' => fn (array $attributes) => AirportTransferBooking::query()
                ->findOrFail($attributes['airport_transfer_booking_id'])->service_ends_at,
            'assigned_at' => now(),
            'unassigned_at' => null,
            'unassigned_by_user_id' => null,
            'unassignment_reason' => null,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (AirportTransferAssignment $assignment): void {
            if ($assignment->isActive()) {
                $assignment->booking()->update([
                    'assigned_vehicle_id' => $assignment->vehicle_id,
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
