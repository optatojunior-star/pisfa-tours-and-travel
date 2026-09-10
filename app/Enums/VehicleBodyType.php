<?php

namespace App\Enums;

/**
 * The body shapes PISFA hires out, sells, and imports.
 *
 * Body, fuel, transmission, drive and condition used to be free-text boxes with
 * a "lowercase key, for example suv" hint under them. That asked whoever was
 * publishing a vehicle to guess the vocabulary, and the guesses did not agree:
 * the showroom held "Diesel" while the hire fleet held "diesel", so a filter
 * built on one silently missed the other.
 *
 * The values stay lowercase snake_case because that is what the existing rows
 * already contain, so adopting this list needs no data rewrite for hire stock.
 */
enum VehicleBodyType: string
{
    case Sedan = 'sedan';
    case Suv = 'suv';
    case Hatchback = 'hatchback';
    case Wagon = 'wagon';
    case Coupe = 'coupe';
    case Convertible = 'convertible';
    case Pickup = 'pickup';
    case Van = 'van';
    case Minivan = 'minivan';
    case Minibus = 'minibus';
    case Bus = 'bus';
    case SafariVan = 'safari_van';
    case Truck = 'truck';

    public function label(): string
    {
        return match ($this) {
            self::Sedan => 'Saloon / Sedan',
            self::Suv => 'SUV',
            self::Hatchback => 'Hatchback',
            self::Wagon => 'Station wagon',
            self::Coupe => 'Coupe',
            self::Convertible => 'Convertible',
            self::Pickup => 'Pickup',
            self::Van => 'Van',
            self::Minivan => 'Minivan / MPV',
            self::Minibus => 'Minibus',
            self::Bus => 'Bus / Coaster',
            self::SafariVan => 'Safari van (pop-up roof)',
            self::Truck => 'Truck / Lorry',
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
