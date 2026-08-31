<?php

namespace App\Enums;

enum TourTravelerType: string
{
    case Adult = 'adult';
    case Child = 'child';

    public function label(): string
    {
        return match ($this) {
            self::Adult => 'Adult',
            self::Child => 'Child',
        };
    }
}
