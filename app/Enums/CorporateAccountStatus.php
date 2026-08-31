<?php

namespace App\Enums;

/**
 * Where a company stands with PISFA.
 *
 * `Suspended` exists so an account behind on payment can be stopped from
 * booking on credit without closing the relationship or losing the terms that
 * were negotiated.
 */
enum CorporateAccountStatus: string
{
    case Prospect = 'prospect';
    case Active = 'active';
    case Suspended = 'suspended';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Prospect => 'Prospect',
            self::Active => 'Active',
            self::Suspended => 'Suspended',
            self::Closed => 'Closed',
        };
    }

    /** Whether the account may book and be invoiced on terms. */
    public function canTrade(): bool
    {
        return $this === self::Active;
    }

    public function isEditable(): bool
    {
        return $this !== self::Closed;
    }

    public function tone(): string
    {
        return match ($this) {
            self::Prospect => 'slate',
            self::Active => 'emerald',
            self::Suspended => 'amber',
            self::Closed => 'rose',
        };
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Prospect => [self::Active, self::Closed],
            self::Active => [self::Suspended, self::Closed],
            self::Suspended => [self::Active, self::Closed],
            // Closed returns through Prospect, so terms are agreed again rather
            // than an old credit limit quietly coming back to life.
            self::Closed => [self::Prospect],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }
}
