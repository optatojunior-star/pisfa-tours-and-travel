<?php

namespace App\Support;

use App\Enums\HireMode;
use App\Models\Vehicle;
use App\Models\VehicleHireRate;
use Illuminate\Support\Collection;

/**
 * Turns a handful of vehicles into comparison rows.
 *
 * The useful part of a comparison is not the specification; it is the
 * *differences*. A table where eleven of thirteen rows read the same in every
 * column has buried the two that matter, and the reader has to find them by
 * eye. So each row records whether its values actually differ, and the table
 * can lead with those and let the rest recede.
 *
 * Prices are read from the rate already eager-loaded by the caller, which is
 * the current effective one. Nothing here queries.
 */
final class VehicleComparison
{
    /**
     * @param  Collection<int, Vehicle>  $vehicles
     * @return list<array{label: string, help: ?string, values: list<string>, differs: bool}>
     */
    public static function rows(Collection $vehicles): array
    {
        $spec = VehicleSpecification::class;

        $rows = [
            self::row('Daily rate, self-drive', $vehicles, fn (Vehicle $v): string => self::price($v, HireMode::SelfDrive)),
            self::row('Daily rate, with a driver', $vehicles, fn (Vehicle $v): string => self::price($v, HireMode::WithDriver)),
            self::row('Seats', $vehicles, fn (Vehicle $v): string => (string) $v->seating_capacity),
            self::row('Luggage', $vehicles, fn (Vehicle $v): string => $v->luggage_capacity.' large bags'),
            self::row(
                'Drive',
                $vehicles,
                fn (Vehicle $v): string => $spec::label($spec::driveTypes(), $v->drive_type),
                'On murram roads in the wet season this is the difference between arriving and not.',
            ),
            self::row('Engine', $vehicles, fn (Vehicle $v): string => $spec::formatEngine($v->engine_cc) ?? 'Not stated'),
            self::row(
                'Fuel',
                $vehicles,
                fn (Vehicle $v): string => $spec::label($spec::fuelTypes(), $v->fuel_type),
                'Diesel costs less per kilometre on a long upcountry trip.',
            ),
            self::row('Transmission', $vehicles, fn (Vehicle $v): string => $spec::label($spec::transmissions(), $v->transmission)),
            self::row('Body type', $vehicles, fn (Vehicle $v): string => $spec::label($spec::bodyTypes(), $v->vehicle_type)),
            self::row('Condition', $vehicles, fn (Vehicle $v): string => $spec::label($spec::conditions(), $v->condition)),
            self::row('Colour', $vehicles, fn (Vehicle $v): string => (string) $v->color),
            self::row('Security deposit', $vehicles, fn (Vehicle $v): string => self::deposit($v)),
        ];

        // Differences first. Everything the vehicles share is still shown —
        // hiding it would make the reader wonder what was left out — but it
        // belongs below the answer, not above it.
        usort($rows, static fn (array $a, array $b): int => ($b['differs'] <=> $a['differs']));

        return $rows;
    }

    /**
     * @param  Collection<int, Vehicle>  $vehicles
     * @param  callable(Vehicle): string  $value
     * @return array{label: string, help: ?string, values: list<string>, differs: bool}
     */
    private static function row(string $label, Collection $vehicles, callable $value, ?string $help = null): array
    {
        $values = $vehicles->map($value)->values()->all();

        return [
            'label' => $label,
            'help' => $help,
            'values' => $values,
            // "Not stated" in every column is agreement about nothing, so it
            // does not count as a difference worth promoting.
            'differs' => count(array_unique($values)) > 1,
        ];
    }

    private static function currentRate(Vehicle $vehicle): ?VehicleHireRate
    {
        return $vehicle->relationLoaded('bookableHireRates')
            ? $vehicle->bookableHireRates->first()
            : $vehicle->bookableHireRates()->first();
    }

    private static function price(Vehicle $vehicle, HireMode $mode): string
    {
        $rate = self::currentRate($vehicle);
        $minor = $rate?->rateFor($mode);

        if ($rate === null || $minor === null || $minor < 1) {
            return 'Not offered';
        }

        return Money::format((int) $minor, $rate->currency).' / day';
    }

    private static function deposit(Vehicle $vehicle): string
    {
        $rate = self::currentRate($vehicle);

        if ($rate === null) {
            return 'Not stated';
        }

        $minor = (int) $rate->security_deposit_minor;

        return $minor < 1 ? 'None' : Money::format($minor, $rate->currency);
    }
}
