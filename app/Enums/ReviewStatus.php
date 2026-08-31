<?php

namespace App\Enums;

/**
 * Only Published is ever visible to the public. Everything else is restricted
 * to the author and to moderators.
 */
enum ReviewStatus: string
{
    case Pending = 'pending';
    case Published = 'published';
    case Unpublished = 'unpublished';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Awaiting moderation',
            self::Published => 'Published',
            self::Unpublished => 'Unpublished',
            self::Rejected => 'Rejected',
        };
    }

    /** The single source of truth for public visibility. */
    public function isPublic(): bool
    {
        return $this === self::Published;
    }

    /**
     * Only a published review contributes to a subject's rating summary. A
     * pending or withdrawn review must not move the average.
     */
    public function countsTowardsSummary(): bool
    {
        return $this->isPublic();
    }

    /**
     * A customer may revise a review that has not yet been judged, or one that
     * was rejected, so they can address the reason. Editing a published review
     * returns it to moderation.
     */
    public function isCustomerEditable(): bool
    {
        return in_array($this, [self::Pending, self::Published, self::Rejected], true);
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Published, self::Rejected],
            self::Published => [self::Unpublished, self::Pending],
            self::Unpublished => [self::Published, self::Rejected],
            self::Rejected => [self::Pending],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    /** @return list<string> */
    public static function publicValues(): array
    {
        return array_map(
            static fn (self $s): string => $s->value,
            array_filter(self::cases(), static fn (self $s): bool => $s->isPublic()),
        );
    }
}
