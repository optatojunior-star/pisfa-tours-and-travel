<?php

namespace App\Support\Payments;

use App\Enums\PaymentStatus;

/**
 * The outcome of asking a provider to start collecting a payment.
 *
 * Adapters translate a provider's own vocabulary into this shape. Nothing
 * outside an adapter sees a raw provider status string, which is what keeps
 * PaymentStatus the single status vocabulary in the system.
 */
final class InitiationResult
{
    /**
     * @param  array<string, mixed>  $instructions  Shown to the customer (bank details, USSD prompt).
     * @param  array<string, mixed>  $metadata  Stored on the payment for support and reconciliation.
     */
    private function __construct(
        public readonly PaymentStatus $status,
        public readonly ?string $redirectUrl = null,
        public readonly ?string $providerReference = null,
        public readonly ?string $providerTransactionId = null,
        public readonly array $instructions = [],
        public readonly array $metadata = [],
        public readonly ?string $failureReason = null,
    ) {}

    /** The customer must be sent to the provider to complete payment. */
    public static function redirect(
        string $url,
        ?string $providerReference = null,
        array $metadata = [],
    ): self {
        return new self(
            status: PaymentStatus::RequiresAction,
            redirectUrl: $url,
            providerReference: $providerReference,
            metadata: $metadata,
        );
    }

    /** The provider has been asked to prompt the customer (mobile-money push). */
    public static function awaitingApproval(
        ?string $providerReference = null,
        array $instructions = [],
        array $metadata = [],
    ): self {
        return new self(
            status: PaymentStatus::RequiresAction,
            providerReference: $providerReference,
            instructions: $instructions,
            metadata: $metadata,
        );
    }

    /** No API call; the customer receives instructions and pays out of band. */
    public static function manualInstructions(array $instructions, array $metadata = []): self
    {
        return new self(
            status: PaymentStatus::Pending,
            instructions: $instructions,
            metadata: $metadata,
        );
    }

    /**
     * Settled immediately. Only ever legitimate for a staff-recorded receipt
     * where the money is already in hand.
     */
    public static function settled(?string $providerTransactionId = null, array $metadata = []): self
    {
        return new self(
            status: PaymentStatus::Paid,
            providerTransactionId: $providerTransactionId,
            metadata: $metadata,
        );
    }

    public static function failed(string $reason, array $metadata = []): self
    {
        return new self(
            status: PaymentStatus::Failed,
            metadata: $metadata,
            failureReason: $reason,
        );
    }
}
