<?php

namespace App\Enums;

enum FlightTripType: string
{
    case OneWay = 'one_way';
    case Return = 'return';

    public function label(): string
    {
        return match ($this) {
            self::OneWay => 'One way',
            self::Return => 'Return',
        };
    }

    public function requiresReturnDate(): bool
    {
        return $this === self::Return;
    }
}
