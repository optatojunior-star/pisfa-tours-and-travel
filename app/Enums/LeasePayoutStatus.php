<?php

namespace App\Enums;

/**
 * Where a month's money owed to an owner has got to.
 *
 * `Paid` is terminal and carries the reference of the transfer. A payout
 * corrected after the money moved is a new adjustment, not an edit, because the
 * owner already has a statement saying what they were sent.
 */
enum LeasePayoutStatus: string
{
    case Draft = 'draft';
    case Approved = 'approved';
    case Paid = 'paid';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Approved => 'Approved for payment',
            self::Paid => 'Paid',
            self::Cancelled => 'Cancelled',
        };
    }

    /** Whether the owner may see it in their statement. */
    public function isVisibleToOwner(): bool
    {
        return $this !== self::Draft;
    }

    /** Figures may only be recomputed while nothing has been promised. */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    public function tone(): string
    {
        return match ($this) {
            self::Draft => 'slate',
            self::Approved => 'sky',
            self::Paid => 'emerald',
            self::Cancelled => 'rose',
        };
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Approved, self::Cancelled],
            self::Approved => [self::Paid, self::Draft, self::Cancelled],
            self::Paid, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }
}
