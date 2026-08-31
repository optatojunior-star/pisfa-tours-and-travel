<?php

namespace App\Enums;

enum VehicleImportBodyType: string
{
    case Sedan = 'sedan';
    case Suv = 'suv';
    case Hatchback = 'hatchback';
    case Wagon = 'wagon';
    case Pickup = 'pickup';
    case Van = 'van';
    case Minibus = 'minibus';
    case Bus = 'bus';
    case Truck = 'truck';
    case Coupe = 'coupe';

    public function label(): string
    {
        return match ($this) {
            self::Sedan => 'Sedan',
            self::Suv => 'SUV',
            self::Hatchback => 'Hatchback',
            self::Wagon => 'Station wagon',
            self::Pickup => 'Pickup',
            self::Van => 'Van',
            self::Minibus => 'Minibus',
            self::Bus => 'Bus',
            self::Truck => 'Truck',
            self::Coupe => 'Coupe',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
