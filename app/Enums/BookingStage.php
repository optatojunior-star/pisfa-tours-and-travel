<?php

namespace App\Enums;

/**
 * The common vocabulary the unified booking list speaks.
 *
 * Each domain keeps its own richer status; this is only the coarse bucket the
 * cross-domain screen groups and filters by, so an operator can ask "what is
 * awaiting action?" without knowing that a transfer says Declined where an
 * import says Cancelled.
 *
 * The mapping lives on each domain's own status enum, because which of its
 * states counts as "in progress" is the domain's knowledge, not this enum's.
 */
enum BookingStage: string
{
    case AwaitingAction = 'awaiting_action';
    case Confirmed = 'confirmed';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::AwaitingAction => 'Awaiting action',
            self::Confirmed => 'Confirmed',
            self::InProgress => 'In progress',
            self::Completed => 'Completed',
            self::Closed => 'Closed',
        };
    }

    /** Still live work: it needs somebody to do something. */
    public function isOpen(): bool
    {
        return in_array($this, [self::AwaitingAction, self::Confirmed, self::InProgress], true);
    }

    /** Tailwind tone for the badge, kept with the meaning rather than in a view. */
    public function tone(): string
    {
        return match ($this) {
            self::AwaitingAction => 'amber',
            self::Confirmed => 'sky',
            self::InProgress => 'emerald',
            self::Completed => 'slate',
            self::Closed => 'rose',
        };
    }

    /** @return list<string> */
    public static function openValues(): array
    {
        return array_map(
            static fn (self $case): string => $case->value,
            array_values(array_filter(self::cases(), static fn (self $case): bool => $case->isOpen())),
        );
    }
}
