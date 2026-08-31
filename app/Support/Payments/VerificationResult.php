<?php

namespace App\Support\Payments;

use App\Enums\PaymentStatus;

/**
 * The provider's authoritative view of a payment.
 *
 * `amountMinor` and `currency` are what the provider says it actually
 * collected. The settlement action compares them against the stored intent and
 * refuses a mismatch, so a tampered or misrouted callback cannot settle a
 * booking for the wrong sum.
 */
final class VerificationResult
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public readonly PaymentStatus $status,
        public readonly ?int $amountMinor = null,
        public readonly ?string $currency = null,
        public readonly ?string $providerTransactionId = null,
        public readonly ?string $failureReason = null,
        public readonly array $metadata = [],
    ) {}

    public function isSettled(): bool
    {
        return $this->status->isSettled();
    }

    /**
     * True when the provider reported an amount we can check. A provider that
     * omits the amount is treated as unverifiable rather than as matching.
     */
    public function hasAmount(): bool
    {
        return $this->amountMinor !== null && $this->currency !== null;
    }
}
