<?php

namespace App\Enums;

enum FlightInquiryScope: string
{
    case Domestic = 'domestic';
    case International = 'international';

    public function label(): string
    {
        return match ($this) {
            self::Domestic => 'Domestic flight',
            self::International => 'International flight',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Domestic => 'Flights between airstrips and airports inside Uganda.',
            self::International => 'Flights departing from or arriving into Uganda from abroad.',
        };
    }
}
