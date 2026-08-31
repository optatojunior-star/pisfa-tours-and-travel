<?php

namespace App\Enums;

/**
 * Payment progress is part of the status because it is what staff filter on.
 * "Overdue" deliberately is **not** a case: it is derived from the due date and
 * the live balance, so it can never disagree with the money actually received.
 */
enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Cancelled = 'cancelled';
    case Void = 'void';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Issued => 'Issued',
            self::PartiallyPaid => 'Partially paid',
            self::Paid => 'Paid',
            self::Cancelled => 'Cancelled',
            self::Void => 'Void',
        };
    }

    /** Line items and pricing may only change while the invoice is a draft. */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    /** A draft invoice is internal; everything else has been sent out. */
    public function isVisibleToCustomer(): bool
    {
        return $this !== self::Draft;
    }

    /** Whether money may still be collected against it. */
    public function collectsPayment(): bool
    {
        return in_array($this, [self::Issued, self::PartiallyPaid], true);
    }

    /** Counted in receivables reporting. */
    public function isOutstanding(): bool
    {
        return $this->collectsPayment();
    }

    /**
     * Already published and still a live document.
     *
     * Deliberately narrower than isVisibleToCustomer(): a cancelled or voided
     * invoice is visible but closed, so re-issuing it must be refused rather
     * than quietly treated as a repeat of something already done.
     */
    public function hasBeenIssued(): bool
    {
        return in_array($this, [self::Issued, self::PartiallyPaid, self::Paid], true);
    }

    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Issued, self::Cancelled],
            // Payment progress is driven by settlement, not by a human.
            self::Issued => [self::PartiallyPaid, self::Paid, self::Cancelled, self::Void],
            self::PartiallyPaid => [self::Paid, self::Void],
            // A paid invoice cannot be cancelled — money changed hands. It can
            // only be voided, which is an explicit, audited correction.
            self::Paid => [self::Void],
            self::Cancelled, self::Void => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    /** @return list<string> */
    public static function outstandingValues(): array
    {
        return array_map(
            static fn (self $case): string => $case->value,
            array_values(array_filter(
                self::cases(),
                static fn (self $case): bool => $case->isOutstanding(),
            )),
        );
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
