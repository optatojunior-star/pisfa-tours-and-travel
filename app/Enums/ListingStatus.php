<?php

namespace App\Enums;

/**
 * Where a showroom listing is in its sale.
 *
 * `Reserved` exists so a deposit takes a car off the market without claiming it
 * has been sold: the money may still fall through, and a listing that jumped
 * straight to Sold would have to be un-sold, which is not a thing an inventory
 * record should have to do.
 */
enum ListingStatus: string
{
    case Draft = 'draft';
    case Available = 'available';
    case Reserved = 'reserved';
    case Sold = 'sold';
    case Withdrawn = 'withdrawn';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Available => 'Available',
            self::Reserved => 'Reserved',
            self::Sold => 'Sold',
            self::Withdrawn => 'Withdrawn',
        };
    }

    /** Whether the listing appears in the public showroom at all. */
    public function isPubliclyVisible(): bool
    {
        return in_array($this, [self::Available, self::Reserved, self::Sold], true);
    }

    /** Whether a visitor may still enquire about it. */
    public function acceptsEnquiries(): bool
    {
        return in_array($this, [self::Available, self::Reserved], true);
    }

    /** Price and specification may only change before it is committed. */
    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::Available, self::Reserved], true);
    }

    /** Whether the vehicle is still on the books as inventory. */
    public function holdsStock(): bool
    {
        return in_array($this, [self::Available, self::Reserved], true);
    }

    public function tone(): string
    {
        return match ($this) {
            self::Draft => 'slate',
            self::Available => 'emerald',
            self::Reserved => 'amber',
            self::Sold => 'sky',
            self::Withdrawn => 'rose',
        };
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Available, self::Withdrawn],
            self::Available => [self::Reserved, self::Sold, self::Withdrawn],
            // A reservation that falls through returns the car to the market.
            self::Reserved => [self::Sold, self::Available, self::Withdrawn],
            // Sold is final. A mistaken sale is corrected by a new listing, so
            // the record of what was sold and when is never rewritten.
            self::Sold => [],
            self::Withdrawn => [self::Draft],
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
            static fn (self $case): string => $case->value,
            array_values(array_filter(self::cases(), static fn (self $c): bool => $c->isPubliclyVisible())),
        );
    }
}
