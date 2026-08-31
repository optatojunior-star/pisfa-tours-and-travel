<?php

namespace App\Enums;

enum VehicleImportTransmission: string
{
    case Automatic = 'automatic';
    case Manual = 'manual';
    case Cvt = 'cvt';

    public function label(): string
    {
        return match ($this) {
            self::Automatic => 'Automatic',
            self::Manual => 'Manual',
            self::Cvt => 'CVT',
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
