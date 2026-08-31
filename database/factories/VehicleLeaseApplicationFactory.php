<?php

namespace Database\Factories;

use App\Enums\LeaseApplicationStatus;
use App\Enums\LeasePayoutModel;
use App\Models\User;
use App\Models\VehicleLeaseApplication;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<VehicleLeaseApplication> */
class VehicleLeaseApplicationFactory extends Factory
{
    public function definition(): array
    {
        $key = (string) Str::uuid();

        return [
            'reference' => 'LEASE-'.Str::upper((string) Str::ulid()),
            'owner_id' => null,
            'status' => LeaseApplicationStatus::Submitted,
            'contact_name' => fake()->name(),
            'contact_email' => fake()->unique()->safeEmail(),
            'contact_phone' => '+2567'.fake()->numerify('########'),
            'make' => 'Toyota',
            'model' => 'Hiace',
            'year' => 2018,
            'registration_plate' => 'UBA'.fake()->unique()->numerify('###').'X',
            'colour' => 'White',
            'transmission' => 'Manual',
            'fuel_type' => 'Diesel',
            'seating_capacity' => 14,
            'mileage_km' => 120000,
            'condition' => 'Good',
            'preferred_payout_model' => LeasePayoutModel::RevenueShare,
            'expected_monthly_minor' => null,
            'expected_currency' => null,
            'idempotency_owner_hash' => hash_hmac('sha256', $key, (string) config('app.key')),
            'idempotency_key' => $key,
        ];
    }

    public function fromOwner(User $owner): static
    {
        return $this->state(fn (): array => [
            'owner_id' => $owner->getKey(),
            'contact_name' => $owner->name,
            'contact_email' => $owner->email,
        ]);
    }

    public function status(LeaseApplicationStatus $status): static
    {
        return $this->state(fn (): array => [
            'status' => $status,
            'closed_at' => $status->isOpen() ? null : now()->subDay(),
        ]);
    }

    public function plated(string $plate): static
    {
        return $this->state(fn (): array => ['registration_plate' => $plate]);
    }
}
