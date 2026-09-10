<?php

namespace App\Enums;

/** What the vehicle burns. Values match VehicleImportFuelType so the three vehicle modules agree. */
enum VehicleFuelType: string
{
    case Petrol = 'petrol';
    case Diesel = 'diesel';
    case Hybrid = 'hybrid';
    case PluginHybrid = 'plugin_hybrid';
    case Electric = 'electric';
    case Lpg = 'lpg';

    public function label(): string
    {
        return match ($this) {
            self::Petrol => 'Petrol',
            self::Diesel => 'Diesel',
            self::Hybrid => 'Hybrid',
            self::PluginHybrid => 'Plug-in hybrid',
            self::Electric => 'Electric',
            self::Lpg => 'LPG / Gas',
        };
    }

    /** An electric vehicle has no displacement, so the engine-size field does not apply to it. */
    public function hasDisplacement(): bool
    {
        return $this !== self::Electric;
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
