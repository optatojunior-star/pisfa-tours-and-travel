<?php

namespace App\Enums;

/**
 * Where a claim has got to.
 *
 * `Reimbursed` is separate from `Approved` because approving a claim and
 * actually sending somebody their money are different events, days apart, and a
 * driver who is out of pocket needs to see which of the two has happened.
 */
enum ExpenseStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Reimbursed = 'reimbursed';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Submitted => 'Awaiting approval',
            self::Approved => 'Approved',
            self::Reimbursed => 'Reimbursed',
            self::Rejected => 'Rejected',
        };
    }

    /** Figures may only change before anybody has approved them. */
    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::Submitted], true);
    }

    /** Whether it counts towards what the business has actually spent. */
    public function countsAsSpend(): bool
    {
        return in_array($this, [self::Approved, self::Reimbursed], true);
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::Draft, self::Submitted, self::Approved], true);
    }

    public function tone(): string
    {
        return match ($this) {
            self::Draft => 'slate',
            self::Submitted => 'amber',
            self::Approved => 'sky',
            self::Reimbursed => 'emerald',
            self::Rejected => 'rose',
        };
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Submitted],
            self::Submitted => [self::Approved, self::Rejected, self::Draft],
            self::Approved => [self::Reimbursed, self::Rejected],
            self::Reimbursed, self::Rejected => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    /** @return list<string> */
    public static function spendValues(): array
    {
        return array_map(
            static fn (self $case): string => $case->value,
            array_values(array_filter(self::cases(), static fn (self $case): bool => $case->countsAsSpend())),
        );
    }
}
