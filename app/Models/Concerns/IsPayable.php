<?php

namespace App\Models\Concerns;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Shared Payable implementation for booking-style records.
 *
 * A consuming model supplies its own total, currency, owner, and payability
 * rule; everything derived from settled allocations lives here so the balance
 * calculation cannot drift between domains.
 */
trait IsPayable
{
    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payable')->latest('id');
    }

    public function paymentAllocations(): MorphMany
    {
        return $this->morphMany(PaymentAllocation::class, 'allocatable');
    }

    public function paymentReference(): string
    {
        return (string) $this->reference;
    }

    public function payableCurrency(): string
    {
        return strtoupper((string) $this->currency);
    }

    public function payer(): ?User
    {
        $customer = $this->customer;

        return $customer instanceof User ? $customer : null;
    }

    /**
     * Only allocations backed by a settled payment reduce the balance. A
     * pending intent must never make a booking look paid.
     */
    public function settledAmountMinor(): int
    {
        return (int) $this->paymentAllocations()
            ->whereHas('payment', fn (Builder $query): Builder => $query->whereIn(
                'status',
                PaymentStatus::settledValues(),
            ))
            ->sum('amount_minor');
    }

    public function outstandingAmountMinor(): int
    {
        return max(0, $this->payableAmountMinor() - $this->settledAmountMinor());
    }

    public function isPaidInFull(): bool
    {
        return $this->outstandingAmountMinor() === 0 && $this->payableAmountMinor() > 0;
    }
}
