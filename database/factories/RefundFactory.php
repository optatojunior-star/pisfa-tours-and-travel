<?php

namespace Database\Factories;

use App\Enums\RefundStatus;
use App\Models\Payment;
use App\Models\Refund;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Refund> */
class RefundFactory extends Factory
{
    public function definition(): array
    {
        return [
            'reference' => 'REF-'.Str::upper((string) Str::ulid()),
            'payment_id' => Payment::factory()->settled(),
            'status' => RefundStatus::Pending,
            'amount_minor' => 10_000,
            'currency' => 'UGX',
            'reason' => 'Customer cancelled within the free window.',
            'idempotency_key' => (string) Str::uuid(),
        ];
    }

    public function withStatus(RefundStatus $status): static
    {
        return $this->state(fn (): array => [
            'status' => $status,
            'completed_at' => $status === RefundStatus::Completed ? now() : null,
        ]);
    }
}
