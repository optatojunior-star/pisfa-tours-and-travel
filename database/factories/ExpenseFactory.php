<?php

namespace Database\Factories;

use App\Enums\ExpenseCategory;
use App\Enums\ExpenseStatus;
use App\Models\Expense;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Expense> */
class ExpenseFactory extends Factory
{
    public function definition(): array
    {
        return [
            'reference' => 'EXP-'.Str::upper((string) Str::ulid()),
            'incurred_by_user_id' => User::factory(),
            'vehicle_id' => null,
            'status' => ExpenseStatus::Draft,
            'category' => ExpenseCategory::Fuel,
            'spent_on' => now()->subDays(3)->toDateString(),
            // UGX has no minor unit, so the integer is whole shillings.
            'amount_minor' => 150_000,
            'currency' => 'UGX',
            'description' => 'Diesel for the Kampala run',
            'supplier' => 'Shell Ntinda',
            'is_recoverable' => false,
        ];
    }

    public function by(User $user): static
    {
        return $this->state(fn (): array => ['incurred_by_user_id' => $user->getKey()]);
    }

    public function forVehicle(Vehicle $vehicle): static
    {
        return $this->state(fn (): array => ['vehicle_id' => $vehicle->getKey()]);
    }

    public function category(ExpenseCategory $category): static
    {
        return $this->state(fn (): array => ['category' => $category]);
    }

    public function status(ExpenseStatus $status): static
    {
        return $this->state(fn (): array => [
            'status' => $status,
            'submitted_at' => $status === ExpenseStatus::Draft ? null : now()->subDay(),
            'approved_at' => $status->countsAsSpend() ? now()->subHours(6) : null,
        ]);
    }

    public function amount(int $minor, string $currency = 'UGX'): static
    {
        return $this->state(fn (): array => [
            'amount_minor' => $minor,
            'currency' => $currency,
        ]);
    }

    public function spentOn(string $date): static
    {
        return $this->state(fn (): array => ['spent_on' => $date]);
    }

    /** Approved, vehicle-attached, and marked as chargeable to an owner. */
    public function recoverable(): static
    {
        return $this->state(fn (): array => [
            'status' => ExpenseStatus::Approved,
            'approved_at' => now()->subHours(6),
            'category' => ExpenseCategory::Maintenance,
            'is_recoverable' => true,
        ]);
    }
}
