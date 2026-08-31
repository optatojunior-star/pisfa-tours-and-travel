<?php

namespace Database\Factories;

use App\Enums\LeasePayoutModel;
use App\Enums\LeaseStatus;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleLease;
use App\Models\VehicleLeaseApplication;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<VehicleLease> */
class VehicleLeaseFactory extends Factory
{
    public function definition(): array
    {
        return [
            'reference' => 'VL-'.Str::upper((string) Str::ulid()),
            'owner_id' => User::factory(),
            'vehicle_id' => null,
            'application_id' => null,
            'status' => LeaseStatus::Draft,
            'payout_model' => LeasePayoutModel::RevenueShare,
            'monthly_retainer_minor' => null,
            // 25% in basis points.
            'revenue_share_bps' => 2500,
            'currency' => 'UGX',
            'starts_on' => now()->subMonths(2)->startOfMonth()->toDateString(),
            'ends_on' => null,
            'notice_period_days' => 30,
        ];
    }

    public function ownedBy(User $owner): static
    {
        return $this->state(fn (): array => ['owner_id' => $owner->getKey()]);
    }

    public function forVehicle(Vehicle $vehicle): static
    {
        return $this->state(fn (): array => ['vehicle_id' => $vehicle->getKey()]);
    }

    public function fromApplication(VehicleLeaseApplication $application): static
    {
        return $this->state(fn (): array => ['application_id' => $application->getKey()]);
    }

    public function active(): static
    {
        return $this->state(fn (): array => [
            'status' => LeaseStatus::Active,
            'activated_at' => now()->subMonths(2),
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn (): array => [
            'status' => LeaseStatus::Suspended,
            'activated_at' => now()->subMonths(2),
            'suspended_at' => now()->subDay(),
            'suspension_reason' => 'Insurance certificate expired.',
        ]);
    }

    public function ended(): static
    {
        return $this->state(fn (): array => [
            'status' => LeaseStatus::Ended,
            'activated_at' => now()->subMonths(6),
            'ended_at' => now()->subDay(),
            'ends_on' => now()->subDay()->toDateString(),
            'termination_reason' => 'The owner sold the vehicle.',
        ]);
    }

    public function sharing(int $bps, string $currency = 'UGX'): static
    {
        return $this->state(fn (): array => [
            'payout_model' => LeasePayoutModel::RevenueShare,
            'revenue_share_bps' => $bps,
            'monthly_retainer_minor' => null,
            'currency' => $currency,
        ]);
    }

    public function retainer(int $minor, string $currency = 'UGX'): static
    {
        return $this->state(fn (): array => [
            'payout_model' => LeasePayoutModel::FixedMonthly,
            'monthly_retainer_minor' => $minor,
            'revenue_share_bps' => null,
            'currency' => $currency,
        ]);
    }
}
