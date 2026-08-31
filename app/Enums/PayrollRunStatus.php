<?php

namespace App\Enums;

/**
 * Where a month's payroll has got to.
 *
 * `Approved` is the point where the figures stop moving: staff have been told
 * what they are getting, so a correction after that is a new adjustment rather
 * than an edit to a payslip somebody already has.
 */
enum PayrollRunStatus: string
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

    /** Lines may only be added, changed, or recomputed while it is a draft. */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    /** Whether an employee may see their payslip for this run. */
    public function isVisibleToEmployee(): bool
    {
        return in_array($this, [self::Approved, self::Paid], true);
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
