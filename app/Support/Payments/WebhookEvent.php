<?php

namespace App\Support\Payments;

use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;

/**
 * A normalised, already signature-verified provider notification.
 *
 * `eventId` is the provider's own identifier for the delivery. It is stored
 * under a unique key so a replayed delivery is rejected by the database before
 * any handler runs, rather than by an application check that could race.
 */
final class WebhookEvent
{
    /** @param array<string, mixed> $payload Already redacted. */
    public function __construct(
        public readonly PaymentProvider $provider,
        public readonly string $eventId,
        public readonly ?string $eventType,
        public readonly ?string $paymentReference,
        public readonly ?string $providerTransactionId,
        public readonly ?PaymentStatus $status,
        public readonly ?int $amountMinor,
        public readonly ?string $currency,
        public readonly array $payload = [],
        public readonly ?string $failureReason = null,
    ) {}

    /**
     * An event we understand well enough to act on. Anything else is stored
     * for the audit trail and acknowledged, but changes no business state.
     */
    public function isActionable(): bool
    {
        return $this->status !== null
            && ($this->paymentReference !== null || $this->providerTransactionId !== null);
    }

    public function hasAmount(): bool
    {
        return $this->amountMinor !== null && $this->currency !== null;
    }
}
