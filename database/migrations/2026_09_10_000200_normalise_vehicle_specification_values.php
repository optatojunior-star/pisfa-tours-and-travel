<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Brings existing vehicle rows onto the shared specification vocabulary.
 *
 * The showroom stored "SUV", "Diesel", "Automatic" and the hire fleet stored
 * "suv", "diesel", "automatic" — the same facts, spelled two ways, so a filter
 * built on one silently missed the other and a car moving from hire to sale
 * changed its own specification on the way.
 *
 * Only unambiguous rewrites are made. Anything this map does not recognise is
 * left exactly as it is: VehicleSpecification::optionsPreserving() keeps such a
 * value in its own dropdown and Rule::in keeps it valid, so an unrecognised
 * row stays editable rather than being guessed at here.
 *
 * Condition is the one field deliberately not force-mapped from prose. "good"
 * and "Used" do not say whether a vehicle was imported or bought locally, and
 * that is the distinction the new list draws — inventing an answer would put a
 * claim in front of a buyer that nobody at PISFA made.
 */
return new class extends Migration
{
    /**
     * Lower-casing alone fixes most of it. These are the cases where the old
     * text was not simply the new key in different case.
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

    public function up(): void
    {
        $this->normalise('vehicles', 'vehicle_type', 'body');
        $this->normalise('vehicles', 'fuel_type', 'fuel');
        $this->normalise('vehicles', 'transmission', 'transmission');

        $this->normalise('vehicle_listings', 'body_type', 'body');
        $this->normalise('vehicle_listings', 'fuel_type', 'fuel');
        $this->normalise('vehicle_listings', 'transmission', 'transmission');
    }

    /**
     * Irreversible by design.
     *
     * Rolling back would mean restoring "Diesel" on some rows and "diesel" on
     * others with nothing recording which was which. The column is unchanged in
     * shape, so a rollback of the surrounding migrations needs nothing here.
     */
    public function down(): void {}

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

            if ($key === (string) $value) {
                continue;
            }

            DB::table($table)->where($column, $value)->update([$column => $key]);
        }
    }
};
