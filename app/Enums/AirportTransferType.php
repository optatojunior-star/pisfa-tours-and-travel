<?php

namespace App\Enums;

enum AirportTransferType: string
{
    case Pickup = 'pickup';
    case Dropoff = 'dropoff';

    public function label(): string
    {
        return match ($this) {
            self::Pickup => 'Airport pickup',
            self::Dropoff => 'Airport drop-off',
        };
    }

    public function airportIsOrigin(): bool
    {
        return $this === self::Pickup;
    }
}
