<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Folds the historical vehicle specification spellings onto the shared keys.
 *
 * Lives here rather than inside the migration that calls it for one reason: it
 * rewrites production rows, and its first version passed on SQLite while being
 * wrong on MySQL, so it needs tests. Logic that has to be proven cannot live
 * only inside an anonymous migration class.
 *
 * Only unambiguous rewrites are made. Anything the alias map does not recognise
 * is lower-cased and keyed rather than discarded, and
 * VehicleSpecification::optionsPreserving() keeps whatever comes out valid in
 * its own dropdown, so no row is ever left unsaveable.
 *
 * Condition is deliberately absent. "good" and "Used" do not say whether a
 * vehicle was imported or bought locally, and that is the distinction the new
 * list draws — inventing an answer would put a claim in front of a buyer that
 * nobody at PISFA made.
 */
final class VehicleSpecificationNormaliser
{
    /**
     * Lower-casing alone fixes most of it. These are the cases where the old
     * free text was not simply the new key in a different case.
     *
     * @var array<string, array<string, string>>
     */
    private const ALIASES = [
        'body' => [
            'station wagon' => 'wagon',
            'stationwagon' => 'wagon',
            'estate' => 'wagon',
            'saloon' => 'sedan',
            'mpv' => 'minivan',
            'lorry' => 'truck',
            'coaster' => 'bus',
            '4x4' => 'suv',
            'safari van' => 'safari_van',
            'safari' => 'safari_van',
        ],
        'fuel' => [
            'gas' => 'lpg',
            'gasoline' => 'petrol',
            'petroleum' => 'petrol',
            'plug-in hybrid' => 'plugin_hybrid',
            'plug in hybrid' => 'plugin_hybrid',
            'ev' => 'electric',
        ],
        'transmission' => [
            'auto' => 'automatic',
            'at' => 'automatic',
            'mt' => 'manual',
            'tiptronic' => 'semi_automatic',
            'semi automatic' => 'semi_automatic',
            'semi-automatic' => 'semi_automatic',
        ],
    ];

    /**
     * Every column on both tables. Idempotent: normalising an already
     * normalised value is a no-op, so this is safe to run again.
     */
    public function run(): void
    {
        $this->normalise('vehicles', 'vehicle_type', 'body');
        $this->normalise('vehicles', 'fuel_type', 'fuel');
        $this->normalise('vehicles', 'transmission', 'transmission');

        $this->normalise('vehicle_listings', 'body_type', 'body');
        $this->normalise('vehicle_listings', 'fuel_type', 'fuel');
        $this->normalise('vehicle_listings', 'transmission', 'transmission');
    }

    private function normalise(string $table, string $column, string $dimension): void
    {
        $aliases = self::ALIASES[$dimension];

        // Distinct values only: a fleet of two hundred vehicles has perhaps a
        // dozen spellings between them, and one UPDATE per spelling beats one
        // per row.
        $values = DB::table($table)
            ->whereNotNull($column)
            ->where($column, '<>', '')
            ->distinct()
            ->pluck($column);

        foreach ($values as $value) {
            $key = strtolower(trim((string) $value));
            $key = $aliases[$key] ?? str_replace([' ', '-'], '_', $key);

            /*
             * Written unconditionally, even when the value already equals the
             * key it normalises to.
             *
             * There used to be a `continue` here to skip the no-op, and it was
             * wrong on the only database that matters. MySQL's default
             * collation is case-insensitive, so DISTINCT collapses "Diesel",
             * "diesel" and "DIESEL" into a single arbitrary representative —
             * verified on MariaDB 10.4 to return one row, and to return the
             * already-lowercase spelling. The guard then matched it, skipped
             * the UPDATE, and left every capitalised row un-normalised. SQLite,
             * whose DISTINCT is case-sensitive, returned all three spellings and
             * handled each, which is why the suite was green.
             *
             * Dropping the guard makes both engines correct for the same reason
             * it looked redundant: the UPDATE's own WHERE is case-insensitive on
             * MySQL, so one statement catches every spelling the DISTINCT folded
             * together.
             */
            DB::table($table)->where($column, $value)->update([$column => $key]);
        }
    }
}
