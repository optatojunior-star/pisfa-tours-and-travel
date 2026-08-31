<?php

namespace App\Enums;

enum TourPackageItemType: string
{
    case Inclusion = 'inclusion';
    case Exclusion = 'exclusion';

    public function label(): string
    {
        return match ($this) {
            self::Inclusion => 'Included',
            self::Exclusion => 'Not included',
        };
    }
}
