<?php

namespace App\Enums;

/**
 * A quotation is a priced offer with an expiry. Only Draft is editable: once it
 * has been sent, the customer has seen those numbers, so changing them means
 * issuing a revision rather than silently rewriting the offer.
 */
enum QuotationStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case Accepted = 'accepted';
    case Declined = 'declined';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Sent => 'Sent',
            self::Accepted => 'Accepted',
            self::Declined => 'Declined',
            self::Expired => 'Expired',
            self::Cancelled => 'Cancelled',
        };
    }

    /** Line items and pricing may only change while the offer is a draft. */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    /** Whether the customer may still accept or decline. */
    public function awaitsCustomer(): bool
    {
        return $this === self::Sent;
    }

    /** Visible to the customer at all — a draft is internal. */
    public function isVisibleToCustomer(): bool
    {
        return $this !== self::Draft;
    }

    /** Only an accepted quotation may become an invoice. */
    public function isConvertible(): bool
    {
        return $this === self::Accepted;
    }

    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Sent, self::Cancelled],
            self::Sent => [self::Accepted, self::Declined, self::Expired, self::Cancelled, self::Draft],
            // A declined or expired offer can be revised and re-sent, which
            // returns it to Draft. An accepted one is final.
            self::Declined, self::Expired => [self::Draft, self::Cancelled],
            self::Accepted, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    /** @return list<string> */
    public static function customerVisibleValues(): array
    {
        return array_map(
            static fn (self $case): string => $case->value,
            array_values(array_filter(
                self::cases(),
                static fn (self $case): bool => $case->isVisibleToCustomer(),
            )),
        );
    }
}
