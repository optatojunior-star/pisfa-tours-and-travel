<?php

namespace App\Enums;

/** Gearbox type. Values match VehicleImportTransmission. */
enum VehicleTransmission: string
{
    case Automatic = 'automatic';
    case Manual = 'manual';
    case Cvt = 'cvt';
    case SemiAutomatic = 'semi_automatic';

    public function label(): string
    {
        return match ($this) {
            self::Automatic => 'Automatic',
            self::Manual => 'Manual',
            self::Cvt => 'CVT (automatic)',
            self::SemiAutomatic => 'Semi-automatic / Tiptronic',
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
