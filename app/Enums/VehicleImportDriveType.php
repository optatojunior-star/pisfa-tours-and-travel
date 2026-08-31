<?php

namespace App\Enums;

enum VehicleImportDriveType: string
{
    case TwoWheelDrive = '2wd';
    case FourWheelDrive = '4wd';
    case AllWheelDrive = 'awd';

    public function label(): string
    {
        return match ($this) {
            self::TwoWheelDrive => '2WD',
            self::FourWheelDrive => '4WD',
            self::AllWheelDrive => 'AWD',
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
