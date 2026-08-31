<?php

namespace Database\Factories;

use App\Enums\AccountStatus;
use App\Enums\CarHireBookingStatus;
use App\Enums\HireMode;
use App\Enums\UserRole;
use App\Models\CarHireBooking;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleHireRate;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<CarHireBooking> */
class CarHireBookingFactory extends Factory
{
    public function definition(): array
    {
        $pickupAt = now()->toImmutable()->addDays(10)->setTime(9, 0);
        $returnAt = $pickupAt->addDays(3);

        return [
            'reference' => 'HIRE-'.Str::upper((string) Str::ulid()),
            'customer_id' => User::factory()->state([
                'role' => UserRole::Customer,
                'status' => AccountStatus::Active,
                'email_verified_at' => now(),
                'phone' => '+256700'.fake()->unique()->numerify('######'),
            ]),
            'vehicle_hire_rate_id' => VehicleHireRate::factory(),
            'vehicle_id' => fn (array $attributes): int => VehicleHireRate::query()
                ->findOrFail($attributes['vehicle_hire_rate_id'])->vehicle_id,
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', (string) Str::uuid()),
            'status' => CarHireBookingStatus::Pending,
            'hire_mode' => HireMode::WithDriver,
            'pickup_at' => $pickupAt,
            'return_at' => $returnAt,
            'cancellation_cutoff_at' => $pickupAt->subHours(
                (int) config('car_hire.default_cancellation_cutoff_hours', 48),
            ),
            'hold_expires_at' => now()->addMinutes(
                (int) config('car_hire.pending_hold_minutes', 1440),
            ),
            'billable_days' => 3,
            'vehicle_name_snapshot' => fn (array $attributes): string => $this->vehicle($attributes)->make
                .' '.$this->vehicle($attributes)->model,
            'registration_plate_snapshot' => fn (array $attributes): string => $this->vehicle($attributes)->registration_plate,
            'daily_rate_minor' => fn (array $attributes): int => $this->dailyRate($attributes),
            'rental_subtotal_minor' => fn (array $attributes): int => $attributes['daily_rate_minor']
                * $attributes['billable_days'],
            'security_deposit_minor' => fn (array $attributes): int => $this->rate($attributes)->security_deposit_minor,
            'total_minor' => fn (array $attributes): int => $attributes['rental_subtotal_minor']
                + $attributes['security_deposit_minor'],
            'currency' => fn (array $attributes): string => $this->rate($attributes)->currency,
            'contact_name' => fn (array $attributes): string => $this->customer($attributes)->name,
            'contact_email' => fn (array $attributes): string => $this->customer($attributes)->email,
            'contact_phone' => fn (array $attributes): string => $this->customer($attributes)->phone,
            'pickup_location' => 'PISFA office, Kampala',
            'return_location' => 'PISFA office, Kampala',
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

    public function withDriver(): static
    {
        return $this->state(fn (): array => ['hire_mode' => HireMode::WithDriver]);
    }

    public function selfDrive(): static
    {
        return $this->state(fn (): array => ['hire_mode' => HireMode::SelfDrive]);
    }

    public function confirmed(): static
    {
        return $this->state(fn (): array => [
            'status' => CarHireBookingStatus::Confirmed,
            'confirmed_at' => now(),
        ]);
    }

    public function inProgress(): static
    {
        return $this->state(fn (): array => [
            'status' => CarHireBookingStatus::InProgress,
            'pickup_at' => now()->subDay(),
            'return_at' => now()->addDays(2),
            'cancellation_cutoff_at' => now()->subDays(3),
            'confirmed_at' => now()->subDays(2),
            'in_progress_at' => now()->subDay(),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (): array => [
            'status' => CarHireBookingStatus::Completed,
            'pickup_at' => now()->subDays(4),
            'return_at' => now()->subDay(),
            'cancellation_cutoff_at' => now()->subDays(6),
            'confirmed_at' => now()->subDays(5),
            'in_progress_at' => now()->subDays(4),
            'completed_at' => now()->subDay(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (): array => [
            'status' => CarHireBookingStatus::Cancelled,
            'cancelled_at' => now(),
            'cancellation_reason' => 'Travel plans changed.',
        ]);
    }

    public function declined(): static
    {
        return $this->state(fn (): array => [
            'status' => CarHireBookingStatus::Declined,
            'cancellation_reason' => 'The request could not be approved.',
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => [
            'status' => CarHireBookingStatus::Expired,
            'hold_expires_at' => now()->subMinute(),
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function rate(array $attributes): VehicleHireRate
    {
        return VehicleHireRate::query()->findOrFail($attributes['vehicle_hire_rate_id']);
    }

    /** @param array<string, mixed> $attributes */
    private function vehicle(array $attributes): Vehicle
    {
        return Vehicle::query()->findOrFail($attributes['vehicle_id']);
    }

    /** @param array<string, mixed> $attributes */
    private function customer(array $attributes): User
    {
        return User::query()->findOrFail($attributes['customer_id']);
    }

    /** @param array<string, mixed> $attributes */
    private function dailyRate(array $attributes): int
    {
        $mode = $attributes['hire_mode'] instanceof HireMode
            ? $attributes['hire_mode']
            : HireMode::from($attributes['hire_mode']);
        $dailyRate = $this->rate($attributes)->rateFor($mode);

        if ($dailyRate === null) {
            throw new \LogicException('The factory rate does not support the selected hire mode.');
        }

        return $dailyRate;
    }
}
