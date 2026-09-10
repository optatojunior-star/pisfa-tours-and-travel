<?php

namespace App\Enums;

/**
 * How many wheels are driven.
 *
 * It matters more here than in most markets: a murram road in the wet season is
 * the difference between 2WD and 4WD, and a customer hiring for Kidepo needs to
 * know which one they are getting before they leave Kampala.
 */
enum VehicleDriveType: string
{
    case TwoWheelDrive = '2wd';
    case FourWheelDrive = '4wd';
    case AllWheelDrive = 'awd';

    public function label(): string
    {
        return match ($this) {
            self::TwoWheelDrive => '2WD',
            self::FourWheelDrive => '4WD',
            self::AllWheelDrive => 'AWD (all-wheel drive)',
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
