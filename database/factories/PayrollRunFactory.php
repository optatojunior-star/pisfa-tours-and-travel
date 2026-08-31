<?php

namespace Database\Factories;

use App\Enums\PayrollRunStatus;
use App\Models\PayrollRun;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<PayrollRun> */
class PayrollRunFactory extends Factory
{
    public function definition(): array
    {
        $month = CarbonImmutable::parse(now()->subMonth()->toDateString())->startOfMonth();

        return [
            'reference' => 'PR-'.Str::upper((string) Str::ulid()),
            'status' => PayrollRunStatus::Draft,
            'period_start' => $month->toDateString(),
            'period_end' => $month->endOfMonth()->toDateString(),
            'currency' => 'UGX',
            'gross_total_minor' => 0,
            'deductions_total_minor' => 0,
            'net_total_minor' => 0,
            'employer_cost_minor' => 0,
            'employee_count' => 0,
        ];
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

    public function currency(string $currency): static
    {
        return $this->state(fn (): array => ['currency' => strtoupper($currency)]);
    }

    public function status(PayrollRunStatus $status): static
    {
        return $this->state(fn (): array => [
            'status' => $status,
            'approved_at' => $status === PayrollRunStatus::Draft ? null : now()->subDay(),
            'paid_at' => $status === PayrollRunStatus::Paid ? now() : null,
            'payment_reference' => $status === PayrollRunStatus::Paid ? 'BANK-778899' : null,
        ]);
    }

    /** A run that has somebody on it, so it can be approved. */
    public function withEmployees(int $count = 1): static
    {
        return $this->state(fn (): array => ['employee_count' => $count]);
    }
}
