<?php

namespace App\Enums;

enum VehicleOperationalStatus: string
{
    case Available = 'available';
    case Maintenance = 'maintenance';
    case Unavailable = 'unavailable';
    case Retired = 'retired';

    public function label(): string
    {
        return match ($this) {
            self::Available => 'Operational',
            self::Maintenance => 'Under maintenance',
            self::Unavailable => 'Unavailable',
            self::Retired => 'Retired',
        };
    }

    public function acceptsHire(): bool
    {
        return $this === self::Available;
    }
}
