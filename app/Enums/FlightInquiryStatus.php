<?php

namespace App\Enums;

enum FlightInquiryStatus: string
{
    case New = 'new';
    case Contacted = 'contacted';
    case Booked = 'booked';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::Contacted => 'Contacted',
            self::Booked => 'Booked',
            self::Closed => 'Closed',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * A status that still expects operational follow-up.
     */
    public function isOpen(): bool
    {
        return in_array($this, [self::New, self::Contacted, self::Booked], true);
    }

    /**
     * Reopening returns a resolved inquiry to the follow-up queue. It is a
     * privileged action, so the status graph permits it and the action layer
     * restricts who may perform it.
     */
    public function isReopening(self $next): bool
    {
        return ! $this->isOpen() && $next === self::New;
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::New => [self::Contacted, self::Cancelled],
            self::Contacted => [self::Booked, self::Closed, self::Cancelled],
            self::Booked => [self::Closed, self::Cancelled],
            self::Closed, self::Cancelled => [self::New],
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
            static fn (self $status): string => $status->value,
            array_filter(self::cases(), static fn (self $status): bool => $status->isOpen()),
        );
    }
}
