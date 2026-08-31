<?php

namespace Database\Factories;

use App\Enums\LeasePayoutStatus;
use App\Models\VehicleLease;
use App\Models\VehicleLeasePayout;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<VehicleLeasePayout> */
class VehicleLeasePayoutFactory extends Factory
{
    public function definition(): array
    {
        $month = CarbonImmutable::parse(now()->subMonth()->toDateString())->startOfMonth();

        return [
            'reference' => 'PAY-'.Str::upper((string) Str::ulid()),
            'vehicle_lease_id' => VehicleLease::factory(),
            'status' => LeasePayoutStatus::Draft,
            'period_start' => $month->toDateString(),
            'period_end' => $month->endOfMonth()->toDateString(),
            'gross_revenue_minor' => 2_000_000,
            'hire_count' => 4,
            'revenue_share_bps' => 2500,
            'earned_minor' => 500_000,
            'deductions_minor' => 0,
            'net_payable_minor' => 500_000,
            'currency' => 'UGX',
            'excluded_hire_count' => 0,
        ];
    }

    public function forLease(VehicleLease $lease): static
    {
        return $this->state(fn (): array => [
            'vehicle_lease_id' => $lease->getKey(),
            'currency' => $lease->currency,
        ]);
    }

    public function month(string $anyDate): static
    {
        return $this->state(function () use ($anyDate): array {
            $month = CarbonImmutable::parse($anyDate)->startOfMonth();

            return [
                'period_start' => $month->toDateString(),
                'period_end' => $month->endOfMonth()->toDateString(),
            ];
        });
    }

    public function status(LeasePayoutStatus $status): static
    {
        return $this->state(fn (): array => [
            'status' => $status,
            'approved_at' => $status === LeasePayoutStatus::Draft ? null : now()->subDay(),
            'paid_at' => $status === LeasePayoutStatus::Paid ? now() : null,
            'payment_reference' => $status === LeasePayoutStatus::Paid ? 'MM-12345678' : null,
        ]);
    }
}
