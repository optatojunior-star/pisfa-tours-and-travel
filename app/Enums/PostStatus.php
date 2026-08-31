<?php

namespace App\Enums;

/**
 * A post's editorial state.
 *
 * `Scheduled` is a separate case rather than a published post with a future
 * date, so "will go live" and "is live" are never the same query. A public
 * surface asks for Published *and* a date that has passed, which means a
 * scheduling bug can only ever hide a post, never leak a draft.
 */
enum PostStatus: string
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Published = 'published';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Scheduled => 'Scheduled',
            self::Published => 'Published',
            self::Archived => 'Archived',
        };
    }

    /**
     * Whether this status can appear publicly at all.
     *
     * Necessary but not sufficient: the date is checked too.
     */
    public function isPubliclyVisible(): bool
    {
        return $this === self::Published;
    }

    public function isEditable(): bool
    {
        return $this !== self::Archived;
    }

    public function tone(): string
    {
        return match ($this) {
            self::Draft => 'slate',
            self::Scheduled => 'amber',
            self::Published => 'emerald',
            self::Archived => 'rose',
        };
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Scheduled, self::Published, self::Archived],
            self::Scheduled => [self::Draft, self::Published, self::Archived],
            self::Published => [self::Draft, self::Archived],
            // An archived post is brought back as a draft, so it is reviewed
            // before it is public again.
            self::Archived => [self::Draft],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }
}
