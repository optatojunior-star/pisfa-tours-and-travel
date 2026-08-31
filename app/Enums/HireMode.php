<?php

namespace App\Enums;

enum HireMode: string
{
    case WithDriver = 'with_driver';
    case SelfDrive = 'self_drive';

    public function label(): string
    {
        return match ($this) {
            self::WithDriver => 'With a PISFA driver',
            self::SelfDrive => 'Self-drive',
        };
    }
}
