<?php

namespace App\Enums;

enum TourDepartureStatus: string
{
    case Scheduled = 'scheduled';
    case Closed = 'closed';
    case Cancelled = 'cancelled';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::Scheduled => 'Scheduled',
            self::Closed => 'Closed',
            self::Cancelled => 'Cancelled',
            self::Completed => 'Completed',
        };
    }

    public function acceptsBookings(): bool
    {
        return $this === self::Scheduled;
    }

    public function receivesDepartureReminders(): bool
    {
        return in_array($this, [self::Scheduled, self::Closed], true);
    }

    /** @return list<string> */
    public static function reminderEligibleValues(): array
    {
        return [self::Scheduled->value, self::Closed->value];
    }
}
