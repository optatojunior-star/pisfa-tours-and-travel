<?php

namespace Database\Factories;

use App\Enums\AccountStatus;
use App\Enums\AirportTransferBookingStatus;
use App\Enums\AirportTransferType;
use App\Enums\UserRole;
use App\Models\Airport;
use App\Models\AirportTransferBooking;
use App\Models\AirportTransferLocation;
use App\Models\AirportTransferRate;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<AirportTransferBooking> */
class AirportTransferBookingFactory extends Factory
{
    public function definition(): array
    {
        $serviceStartsAt = now()->toImmutable()->addDays(10)->setTime(9, 0);

        return [
            'reference' => 'TRNSF-'.Str::upper((string) Str::ulid()),
            'customer_id' => User::factory()->state([
                'role' => UserRole::Customer,
                'status' => AccountStatus::Active,
                'email_verified_at' => now(),
                'phone' => '+256700'.fake()->unique()->numerify('######'),
            ]),
            'airport_transfer_rate_id' => AirportTransferRate::factory(),
            'airport_id' => fn (array $attributes): int => $this->rate($attributes)->airport_id,
            'airport_transfer_location_id' => fn (array $attributes): int => $this->rate($attributes)
                ->airport_transfer_location_id,
            'contact_name' => fn (array $attributes): string => $this->customer($attributes)->name,
            'contact_email' => fn (array $attributes): string => $this->customer($attributes)->email,
            'contact_phone' => fn (array $attributes): string => (string) $this->customer($attributes)->phone,
            'idempotency_owner_hash' => fn (array $attributes): string => $this->ownerHash($attributes),
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', (string) Str::uuid()),
            'status' => AirportTransferBookingStatus::Pending,
            'transfer_type' => fn (array $attributes): AirportTransferType => $this->rate($attributes)->transfer_type,
            'airport_code_snapshot' => fn (array $attributes): string => $this->airport($attributes)->code,
            'airport_name_snapshot' => fn (array $attributes): string => $this->airport($attributes)->name,
            'location_name_snapshot' => fn (array $attributes): string => $this->location($attributes)->name,
            'vehicle_type_snapshot' => fn (array $attributes): string => $this->rate($attributes)->vehicle_type,
            'passenger_capacity_snapshot' => fn (array $attributes): int => $this->rate($attributes)
                ->passenger_capacity,
            'luggage_capacity_snapshot' => fn (array $attributes): int => $this->rate($attributes)
                ->luggage_capacity,
            'amount_minor' => fn (array $attributes): int => $this->rate($attributes)->amount_minor,
            'currency' => fn (array $attributes): string => $this->rate($attributes)->currency,
            'estimated_duration_minutes' => fn (array $attributes): int => $this->rate($attributes)
                ->estimated_duration_minutes,
            'service_starts_at' => $serviceStartsAt,
            'service_ends_at' => fn (array $attributes): CarbonImmutable => CarbonImmutable::parse(
                $attributes['service_starts_at'],
            )->addMinutes((int) $attributes['estimated_duration_minutes']),
            'cancellation_cutoff_at' => $serviceStartsAt->subHours(
                (int) config('airport_transfers.default_cancellation_cutoff_hours', 4),
            ),
            'request_expires_at' => now()->addMinutes(
                (int) config('airport_transfers.request_expiry_minutes', 1440),
            ),
            'flight_number' => 'KQ'.fake()->numerify('###'),
            'flight_scheduled_at' => $serviceStartsAt,
            'passenger_count' => 2,
            'luggage_count' => 1,
            'service_address' => fake()->streetAddress().', Kampala',
            'special_requests' => fake()->optional()->sentence(),
            'internal_notes' => null,
            'cancellation_reason' => null,
            'cancelled_by_user_id' => null,
            'assigned_vehicle_id' => null,
            'assigned_driver_user_id' => null,
            'confirmed_at' => null,
            'in_progress_at' => null,
            'completed_at' => null,
            'cancelled_at' => null,
            'declined_at' => null,
            'expired_at' => null,
        ];
    }

    public function guest(): static
    {
        return $this->state(function (): array {
            $email = fake()->unique()->safeEmail();

            return [
                'customer_id' => null,
                'contact_name' => fake()->name(),
                'contact_email' => $email,
                'contact_phone' => '+256701'.fake()->unique()->numerify('######'),
                'idempotency_owner_hash' => hash('sha256', 'guest:'.strtolower($email)),
            ];
        });
    }

    public function pickup(): static
    {
        return $this->state(fn (): array => [
            'airport_transfer_rate_id' => AirportTransferRate::factory()->pickup(),
        ]);
    }

    public function dropoff(): static
    {
        return $this->state(fn (): array => [
            'airport_transfer_rate_id' => AirportTransferRate::factory()->dropoff(),
            'flight_scheduled_at' => fn (array $attributes): CarbonImmutable => CarbonImmutable::parse(
                $attributes['service_starts_at'],
            )->addHours(3),
        ]);
    }

    public function confirmed(): static
    {
        return $this->state(fn (): array => [
            'status' => AirportTransferBookingStatus::Confirmed,
            'confirmed_at' => now(),
        ]);
    }

    public function inProgress(): static
    {
        return $this->state(fn (): array => [
            'status' => AirportTransferBookingStatus::InProgress,
            'service_starts_at' => now()->subMinutes(30),
            'service_ends_at' => fn (array $attributes): CarbonImmutable => CarbonImmutable::parse(
                $attributes['service_starts_at'],
            )->addMinutes((int) $attributes['estimated_duration_minutes']),
            'flight_scheduled_at' => now()->subMinutes(30),
            'cancellation_cutoff_at' => now()->subHours(5),
            'request_expires_at' => now()->subDay(),
            'confirmed_at' => now()->subDay(),
            'in_progress_at' => now()->subMinutes(30),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (): array => [
            'status' => AirportTransferBookingStatus::Completed,
            'service_starts_at' => now()->subHours(3),
            'service_ends_at' => fn (array $attributes): CarbonImmutable => CarbonImmutable::parse(
                $attributes['service_starts_at'],
            )->addMinutes((int) $attributes['estimated_duration_minutes']),
            'flight_scheduled_at' => now()->subHours(3),
            'cancellation_cutoff_at' => now()->subHours(7),
            'request_expires_at' => now()->subDays(2),
            'confirmed_at' => now()->subDays(2),
            'in_progress_at' => now()->subHours(3),
            'completed_at' => now()->subHour(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (): array => [
            'status' => AirportTransferBookingStatus::Cancelled,
            'cancellation_reason' => 'Travel plans changed.',
            'cancelled_at' => now(),
        ]);
    }

    public function declined(): static
    {
        return $this->state(fn (): array => [
            'status' => AirportTransferBookingStatus::Declined,
            'declined_at' => now(),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => [
            'status' => AirportTransferBookingStatus::Expired,
            'request_expires_at' => now()->subMinute(),
            'expired_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function rate(array $attributes): AirportTransferRate
    {
        return AirportTransferRate::query()->findOrFail($attributes['airport_transfer_rate_id']);
    }

    /** @param array<string, mixed> $attributes */
    private function airport(array $attributes): Airport
    {
        return Airport::query()->findOrFail($attributes['airport_id']);
    }

    /** @param array<string, mixed> $attributes */
    private function location(array $attributes): AirportTransferLocation
    {
        return AirportTransferLocation::query()->findOrFail($attributes['airport_transfer_location_id']);
    }

    /** @param array<string, mixed> $attributes */
    private function customer(array $attributes): User
    {
        return User::query()->findOrFail($attributes['customer_id']);
    }

    /** @param array<string, mixed> $attributes */
    private function ownerHash(array $attributes): string
    {
        if ($attributes['customer_id'] !== null) {
            return hash('sha256', 'customer:'.$attributes['customer_id']);
        }

        return hash('sha256', 'guest:'.strtolower((string) $attributes['contact_email']));
    }
}
