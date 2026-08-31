<?php

namespace App\Enums;

enum VehicleImportSteering: string
{
    case RightHand = 'right_hand';
    case LeftHand = 'left_hand';

    public function label(): string
    {
        return match ($this) {
            self::RightHand => 'Right-hand drive',
            self::LeftHand => 'Left-hand drive',
        };
    }

    /**
     * Uganda drives on the left, so right-hand drive is the norm. A left-hand
     * import is legal but unusual and worth flagging to the customer.
     */
    public function isStandardForUganda(): bool
    {
        return $this === self::RightHand;
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
