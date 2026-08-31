<?php

namespace App\Enums;

enum FlightTravelClass: string
{
    case Economy = 'economy';
    case PremiumEconomy = 'premium_economy';
    case Business = 'business';
    case First = 'first';

    public function label(): string
    {
        return match ($this) {
            self::Economy => 'Economy',
            self::PremiumEconomy => 'Premium economy',
            self::Business => 'Business',
            self::First => 'First',
        };
    }
}
