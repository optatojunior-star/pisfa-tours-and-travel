<?php

namespace App\Enums;

/**
 * Whether a property is offered to the public.
 *
 * Publication is a status *and* a date, the same rule the journal uses: the
 * public scope requires both, so a scheduling mistake can only ever hide a
 * property, never leak a draft one.
 */
enum PropertyStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Published => 'Published',
            self::Archived => 'Archived',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Draft => 'slate',
            self::Published => 'emerald',
            self::Archived => 'rose',
        };
    }

    /** An archived property is a record of what was sold, so it is read-only. */
    public function isEditable(): bool
    {
        return $this !== self::Archived;
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Published, self::Archived],
            self::Published => [self::Draft, self::Archived],
            // Archived returns through Draft so it is looked at again before
            // the public sees it.
            self::Archived => [self::Draft],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }
}
