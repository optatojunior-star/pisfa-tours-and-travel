<?php

namespace App\Enums;

/**
 * Where a lease agreement is in its life.
 *
 * `Suspended` exists so a vehicle can be taken off hire — an accident, an
 * expired insurance certificate, a dispute — without ending the agreement and
 * losing the terms that were negotiated.
 */
enum LeaseStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Suspended = 'suspended';
    case Ended = 'ended';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Active => 'Active',
            self::Suspended => 'Suspended',
            self::Ended => 'Ended',
        };
    }

    /** Whether PISFA currently has the vehicle and owes the owner for it. */
    public function isRunning(): bool
    {
        return in_array($this, [self::Active, self::Suspended], true);
    }

    /** Whether the vehicle may be hired out under this lease. */
    public function allowsHire(): bool
    {
        return $this === self::Active;
    }

    /** Terms may only change before the agreement starts. */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    public function tone(): string
    {
        return match ($this) {
            self::Draft => 'slate',
            self::Active => 'emerald',
            self::Suspended => 'amber',
            self::Ended => 'rose',
        };
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Active, self::Ended],
            self::Active => [self::Suspended, self::Ended],
            self::Suspended => [self::Active, self::Ended],
            // Ended is final. A vehicle coming back is a new agreement, so the
            // record of what was agreed and for how long is never rewritten.
            self::Ended => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    /** @return list<string> */
    public static function runningValues(): array
    {
        return array_map(
            static fn (self $case): string => $case->value,
            array_values(array_filter(self::cases(), static fn (self $case): bool => $case->isRunning())),
        );
    }
}
