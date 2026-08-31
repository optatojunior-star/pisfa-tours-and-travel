<?php

namespace App\Enums;

enum PropertyType: string
{
    case Hotel = 'hotel';
    case Lodge = 'lodge';
    case GuestHouse = 'guest_house';
    case Apartment = 'apartment';
    case Cottage = 'cottage';
    case Campsite = 'campsite';

    public function label(): string
    {
        return match ($this) {
            self::Hotel => 'Hotel',
            self::Lodge => 'Safari lodge',
            self::GuestHouse => 'Guest house',
            self::Apartment => 'Serviced apartment',
            self::Cottage => 'Cottage',
            self::Campsite => 'Campsite',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
