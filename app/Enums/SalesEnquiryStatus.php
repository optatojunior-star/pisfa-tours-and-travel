<?php

namespace App\Enums;

enum SalesEnquiryStatus: string
{
    case New = 'new';
    case Contacted = 'contacted';
    case Viewing = 'viewing';
    case Negotiating = 'negotiating';
    case Won = 'won';
    case Lost = 'lost';

    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::Contacted => 'Contacted',
            self::Viewing => 'Viewing arranged',
            self::Negotiating => 'Negotiating',
            self::Won => 'Sold to this buyer',
            self::Lost => 'Lost',
        };
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::New, self::Contacted, self::Viewing, self::Negotiating], true);
    }

    public function tone(): string
    {
        return match ($this) {
            self::New => 'amber',
            self::Contacted, self::Viewing, self::Negotiating => 'sky',
            self::Won => 'emerald',
            self::Lost => 'rose',
        };
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::New => [self::Contacted, self::Lost],
            self::Contacted => [self::Viewing, self::Negotiating, self::Won, self::Lost],
            self::Viewing => [self::Negotiating, self::Won, self::Lost],
            self::Negotiating => [self::Won, self::Lost],
            self::Won, self::Lost => [],
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
            array_values(array_filter(self::cases(), static fn (self $c): bool => $c->isOpen())),
        );
    }
}
