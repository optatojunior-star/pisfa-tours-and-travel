<?php

namespace App\Enums;

enum MaintenanceStatus: string
{
    case Scheduled = 'scheduled';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Scheduled => 'Scheduled',
            self::InProgress => 'In progress',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
        };
    }

    /** Work that has not finished, so the vehicle is still committed to it. */
    public function isOpen(): bool
    {
        return in_array($this, [self::Scheduled, self::InProgress], true);
    }

    /**
     * Only completed work has a real cost and odometer reading, so only
     * completed work counts towards fleet cost reporting.
     */
    public function countsTowardsCost(): bool
    {
        return $this === self::Completed;
    }

    /** Details may only be edited before the record is closed. */
    public function isEditable(): bool
    {
        return $this->isOpen();
    }

    public function tone(): string
    {
        return match ($this) {
            self::Scheduled => 'sky',
            self::InProgress => 'amber',
            self::Completed => 'emerald',
            self::Cancelled => 'rose',
        };
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Scheduled => [self::InProgress, self::Completed, self::Cancelled],
            self::InProgress => [self::Completed, self::Cancelled],
            self::Completed, self::Cancelled => [],
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
