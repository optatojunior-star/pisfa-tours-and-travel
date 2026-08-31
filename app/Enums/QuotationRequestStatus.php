<?php

namespace App\Enums;

/**
 * Lifecycle of a customer's "please quote me" enquiry, which is separate from
 * the priced quotation it eventually produces.
 */
enum QuotationRequestStatus: string
{
    case New = 'new';
    case InReview = 'in_review';
    case Quoted = 'quoted';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::InReview => 'In review',
            self::Quoted => 'Quoted',
            self::Closed => 'Closed',
            self::Cancelled => 'Cancelled',
        };
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::New, self::InReview, self::Quoted], true);
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::New => [self::InReview, self::Quoted, self::Closed, self::Cancelled],
            self::InReview => [self::Quoted, self::Closed, self::Cancelled],
            // A quoted request closes when the quotation is settled either way.
            self::Quoted => [self::Closed, self::Cancelled],
            self::Closed, self::Cancelled => [],
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
