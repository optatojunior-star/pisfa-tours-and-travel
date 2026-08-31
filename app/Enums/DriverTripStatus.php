<?php

namespace App\Enums;

/**
 * A driver's own record of one job, from vehicle collection to hand-back.
 *
 * Deliberately separate from the booking's status: a booking is confirmed by
 * the office, but only the driver can say when the wheels actually turned.
 */
enum DriverTripStatus: string
{
    case Scheduled = 'scheduled';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Abandoned = 'abandoned';

    public function label(): string
    {
        return match ($this) {
            self::Scheduled => 'Not started',
            self::InProgress => 'On the road',
            self::Completed => 'Completed',
            self::Abandoned => 'Abandoned',
        };
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::Scheduled, self::InProgress], true);
    }

    /** Only a completed trip has both readings, so only it has a distance. */
    public function hasDistance(): bool
    {
        return $this === self::Completed;
    }

    public function tone(): string
    {
        return match ($this) {
            self::Scheduled => 'sky',
            self::InProgress => 'amber',
            self::Completed => 'emerald',
            self::Abandoned => 'rose',
        };
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Scheduled => [self::InProgress, self::Abandoned],
            self::InProgress => [self::Completed, self::Abandoned],
            self::Completed, self::Abandoned => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
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
