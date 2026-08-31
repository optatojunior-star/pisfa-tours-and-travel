<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PayrollLine> */
class PayrollLineFactory extends Factory
{
    public function definition(): array
    {
        return [
            'payroll_run_id' => PayrollRun::factory(),
            'user_id' => User::factory(),
            'employee_name_snapshot' => fake()->name(),
            'role_snapshot' => UserRole::Staff,
            'gross_minor' => 1_200_000,
            'allowances_minor' => 0,
            'deductions_minor' => 160_000,
            'net_minor' => 1_040_000,
            'employer_nssf_minor' => 120_000,
            'currency' => 'UGX',
        ];
    }

    public function forRun(PayrollRun $run): static
    {
        return $this->state(fn (): array => [
            'payroll_run_id' => $run->getKey(),
            'currency' => $run->currency,
        ]);
    }

    public function forEmployee(User $employee): static
    {
        return $this->state(fn (): array => [
            'user_id' => $employee->getKey(),
            'employee_name_snapshot' => $employee->name,
            'role_snapshot' => $employee->role,
        ]);
    }
}
