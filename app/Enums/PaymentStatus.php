<?php

namespace App\Enums;

/**
 * The single payment-status vocabulary for the whole system.
 *
 * Every provider's own status strings are mapped into these cases at the
 * gateway boundary. Nothing outside a gateway adapter may invent a status.
 */
enum PaymentStatus: string
{
    /** Intent created; the customer has not been sent to the provider yet. */
    case Pending = 'pending';

    /** Handed to the provider; awaiting an authoritative result. */
    case Processing = 'processing';

    /** Provider needs the customer to act (approve a mobile-money prompt, 3-D Secure). */
    case RequiresAction = 'requires_action';

    /** Funds confirmed by the provider or recorded by an administrator. */
    case Paid = 'paid';

    /** Settled, then partially refunded. Still counts as revenue, net of the refund. */
    case PartiallyRefunded = 'partially_refunded';

    /** Settled, then fully refunded. Contributes nothing to net revenue. */
    case Refunded = 'refunded';

    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /** The intent passed its window without an authoritative result. */
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Processing => 'Processing',
            self::RequiresAction => 'Awaiting your approval',
            self::Paid => 'Paid',
            self::PartiallyRefunded => 'Partially refunded',
            self::Refunded => 'Refunded',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
            self::Expired => 'Expired',
        };
    }

    /**
     * Money has actually been received. Drives revenue reporting and is the
     * only condition under which a service may be treated as paid for.
     */
    public function isSettled(): bool
    {
        return in_array($this, [self::Paid, self::PartiallyRefunded, self::Refunded], true);
    }

    /** Still expecting an outcome; the customer should not be asked to pay again. */
    public function isInFlight(): bool
    {
        return in_array($this, [self::Pending, self::Processing, self::RequiresAction], true);
    }

    /** No further change is possible without a refund. */
    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    public function isRefundable(): bool
    {
        return in_array($this, [self::Paid, self::PartiallyRefunded], true);
    }

    /**
     * A failed or cancelled attempt allows the customer to try again; a settled
     * one never does.
     */
    public function allowsRetry(): bool
    {
        return in_array($this, [self::Failed, self::Cancelled, self::Expired], true);
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [
                self::Processing,
                self::RequiresAction,
                self::Paid,
                self::Failed,
                self::Cancelled,
                self::Expired,
            ],
            self::Processing => [
                self::RequiresAction,
                self::Paid,
                self::Failed,
                self::Cancelled,
                self::Expired,
            ],
            self::RequiresAction => [
                self::Processing,
                self::Paid,
                self::Failed,
                self::Cancelled,
                self::Expired,
            ],
            // Refund transitions are driven by the refund ledger, not by an
            // operator choosing a status directly.
            self::Paid => [self::PartiallyRefunded, self::Refunded],
            self::PartiallyRefunded => [self::Refunded],
            self::Refunded, self::Failed, self::Cancelled, self::Expired => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    /** @return list<string> */
    public static function settledValues(): array
    {
        return array_map(
            static fn (self $status): string => $status->value,
            array_filter(self::cases(), static fn (self $status): bool => $status->isSettled()),
        );
    }

    /** @return list<string> */
    public static function inFlightValues(): array
    {
        return array_map(
            static fn (self $status): string => $status->value,
            array_filter(self::cases(), static fn (self $status): bool => $status->isInFlight()),
        );
    }
}
