<?php

namespace App\Enums;

/**
 * Condition in the terms the Ugandan market actually uses.
 *
 * "Foreign used" and "locally used" are the first question every buyer asks and
 * they are not interchangeable — one has a shipping and clearance history, the
 * other has a local service history. A free-text box holding "good" answered
 * neither.
 */
enum VehicleCondition: string
{
    case BrandNew = 'brand_new';
    case ForeignUsed = 'foreign_used';
    case LocallyUsed = 'locally_used';
    case Refurbished = 'refurbished';

    public function label(): string
    {
        return match ($this) {
            self::BrandNew => 'Brand new',
            self::ForeignUsed => 'Foreign used (imported)',
            self::LocallyUsed => 'Locally used',
            self::Refurbished => 'Refurbished',
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
