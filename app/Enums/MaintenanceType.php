<?php

namespace App\Enums;

enum MaintenanceType: string
{
    case Service = 'service';
    case Repair = 'repair';
    case Inspection = 'inspection';
    case Tyres = 'tyres';
    case Bodywork = 'bodywork';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Service => 'Routine service',
            self::Repair => 'Repair',
            self::Inspection => 'Inspection',
            self::Tyres => 'Tyres',
            self::Bodywork => 'Bodywork',
            self::Other => 'Other',
        };
    }

    /**
     * Whether a completed record of this type should propose a next due point.
     *
     * A routine service recurs on a schedule; a one-off repair does not, and
     * inventing a next-due date for one would put noise in the alert queue.
     */
    public function recurs(): bool
    {
        return in_array($this, [self::Service, self::Inspection, self::Tyres], true);
    }
}
