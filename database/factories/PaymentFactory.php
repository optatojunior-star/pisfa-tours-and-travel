<?php

namespace Database\Factories;

use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Payment> */
class PaymentFactory extends Factory
{
    public function definition(): array
    {
        $amount = fake()->numberBetween(50_000, 5_000_000);

        return [
            'reference' => 'PAY-'.Str::upper((string) Str::ulid()),
            'payable_type' => null,
            'payable_id' => null,
            'customer_id' => null,
            'provider' => PaymentProvider::BankTransfer,
            'status' => PaymentStatus::Pending,
            'amount_minor' => $amount,
            'currency' => 'UGX',
            'base_amount_minor' => $amount,
            'base_currency' => 'UGX',
            'exchange_rate_ppm' => 1_000_000,
            'refunded_amount_minor' => 0,
            'idempotency_owner_hash' => hash('sha256', Str::random(24)),
            'idempotency_key' => (string) Str::uuid(),
            'expires_at' => now()->addHour(),
        ];
    }

    public function settled(): static
    {
        return $this->state(fn (): array => [
            'status' => PaymentStatus::Paid,
            'paid_at' => now(),
        ]);
    }

    public function withStatus(PaymentStatus $status): static
    {
        return $this->state(fn (): array => ['status' => $status]);
    }

    public function usingProvider(PaymentProvider $provider): static
    {
        return $this->state(fn (): array => ['provider' => $provider]);
    }

    public function forPayable(object $payable): static
    {
        return $this->state(fn (): array => [
            'payable_type' => $payable->getMorphClass(),
            'payable_id' => $payable->getKey(),
            'amount_minor' => $payable->payableAmountMinor(),
            'base_amount_minor' => $payable->payableAmountMinor(),
            'currency' => $payable->payableCurrency(),
            'customer_id' => $payable->payer()?->getKey(),
        ]);
    }
}
