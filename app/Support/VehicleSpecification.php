<?php

namespace App\Support;

use App\Enums\VehicleBodyType;
use App\Enums\VehicleCondition;
use App\Enums\VehicleDriveType;
use App\Enums\VehicleFuelType;
use App\Enums\VehicleTransmission;

/**
 * The vehicle specification vocabulary, in one place.
 *
 * Hire, showroom and import each asked for the same six facts about a car and
 * each asked for them differently — three free-text boxes here, five there, a
 * hint saying "lowercase key, for example suv". Whoever was publishing had to
 * invent the vocabulary, and the inventions disagreed.
 *
 * Two things live here rather than in the enums. Engine capacity is a number,
 * not a set of named cases, so it is a list of the displacements actually sold
 * in this market. And `optionsPreserving()` is the rule that makes adopting a
 * closed list safe on a database that already holds open text: a row saying
 * "Used" keeps "Used" in its own dropdown until somebody chooses otherwise,
 * instead of the edit screen quietly resetting it to the first option.
 */
final class VehicleSpecification
{
    /**
     * Engine capacities in cc, as a Ugandan buyer would say them.
     *
     * Deliberately not a range picker. "2000cc" is how the car is advertised on
     * the roadside, in the newspaper and on WhatsApp, so it is how it should be
     * chosen here.
     *
     * @var list<int>
     */
    public const ENGINE_CAPACITIES = [
        660, 800, 1000, 1200, 1300, 1400, 1500, 1600, 1800, 2000, 2200, 2400,
        2500, 2700, 2800, 3000, 3200, 3400, 3500, 4000, 4200, 4500, 4600, 4700,
        5000, 5700, 6000,
    ];

    /** @return array<string, string> */
    public static function bodyTypes(): array
    {
        return VehicleBodyType::options();
    }

    /** @return array<string, string> */
    public static function fuelTypes(): array
    {
        return VehicleFuelType::options();
    }

    /** @return array<string, string> */
    public static function transmissions(): array
    {
        return VehicleTransmission::options();
    }

    /** @return array<string, string> */
    public static function driveTypes(): array
    {
        return VehicleDriveType::options();
    }

    /** @return array<string, string> */
    public static function conditions(): array
    {
        return VehicleCondition::options();
    }

    /**
     * Engine capacities as select options, in natural order — a 1500 is far
     * commoner than a 5700, so sorting by popularity would help nobody find
     * anything.
     *
     * The keys are numeric strings, which PHP silently stores as integers. That
     * is why every option list here is typed by array-key rather than string:
     * pretending otherwise would be a lie the first time somebody looked.
     *
     * @return array<array-key, string>
     */
    public static function engineCapacities(): array
    {
        $options = [];

        foreach (self::ENGINE_CAPACITIES as $cc) {
            $options[(string) $cc] = number_format($cc).' cc'
                .' ('.rtrim(rtrim(number_format($cc / 1000, 1), '0'), '.').'L)';
        }

        return $options;
    }

    /**
     * A dropdown that cannot lose the value a record already holds.
     *
     * If `$current` is not one of the offered options — an older row saying
     * "Used", or a spelling nobody offers any more — it is added at the end,
     * labelled as the current value. The record can then be saved without its
     * specification being rewritten by a screen the user never touched.
     *
     * @param  array<array-key, string>  $options
     * @return array<array-key, string>
     */
    public static function optionsPreserving(array $options, ?string $current): array
    {
        $current = trim((string) $current);

        if ($current === '' || array_key_exists($current, $options)) {
            return $options;
        }

        $options[$current] = str($current)->replace('_', ' ')->ucfirst()->toString().' (current value)';

        return $options;
    }

    /**
     * The values a form may submit for a dimension, including whatever the
     * record already holds. Mirrors optionsPreserving() so validation accepts
     * exactly what the dropdown offered and nothing else.
     *
     * @param  array<array-key, string>  $options
     * @return list<string>
     */
    public static function allowedValues(array $options, ?string $current = null): array
    {
        // Cast back to string: a numeric key such as an engine capacity is an
        // integer in the array and arrives from the form as "2000", and
        // Rule::in compares loosely enough that the mismatch would pass
        // silently until the day it did not.
        return array_map(
            static fn (int|string $key): string => (string) $key,
            array_keys(self::optionsPreserving($options, $current)),
        );
    }

    /**
     * Human text for a stored key, for the public pages.
     *
     * @param  array<array-key, string>  $options
     */
    public static function label(array $options, ?string $value, string $fallback = 'Not stated'): string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return $fallback;
        }

        return $options[$value] ?? str($value)->replace('_', ' ')->ucfirst()->toString();
    }

    /** "2,000 cc (2L)" from a stored integer, or null when it was never recorded. */
    public static function formatEngine(?int $cc): ?string
    {
        if ($cc === null || $cc < 1) {
            return null;
        }

        return self::engineCapacities()[(string) $cc]
            ?? number_format($cc).' cc';
    }
}
