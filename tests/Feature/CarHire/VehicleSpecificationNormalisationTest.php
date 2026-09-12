<?php

namespace Tests\Feature\CarHire;

use App\Models\Vehicle;
use App\Models\VehicleListing;
use App\Support\VehicleSpecificationNormaliser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The data migration that folds the historical specification spellings together.
 *
 * Worth testing directly rather than trusting once: it rewrites existing
 * production rows, and its first version passed on SQLite while being wrong on
 * MySQL. The migration is re-run against rows this test inserts, which is safe
 * because normalising an already-normalised value is a no-op.
 */
class VehicleSpecificationNormalisationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The bug this exists to prevent.
     *
     * MySQL's default collation is case-insensitive, so DISTINCT collapses
     * "Diesel" and "diesel" into one arbitrary representative. The original
     * loop skipped the UPDATE whenever that representative already equalled
     * its normalised form — leaving the other spelling untouched on MySQL while
     * SQLite, whose DISTINCT is case-sensitive, saw both and passed.
     *
     * Both spellings are seeded here so the assertion fails on either database
     * if the guard ever comes back.
     */
    public function test_mixed_case_spellings_of_one_value_are_all_normalised(): void
    {
        $this->vehicle(['fuel_type' => 'diesel', 'transmission' => 'automatic']);
        $this->vehicle(['fuel_type' => 'Diesel', 'transmission' => 'Automatic']);
        $this->vehicle(['fuel_type' => 'DIESEL', 'transmission' => 'AUTOMATIC']);

        $this->runNormalisation();

        $this->assertSame(
            ['diesel'],
            DB::table('vehicles')->distinct()->orderBy('fuel_type')->pluck('fuel_type')->all(),
        );
        $this->assertSame(
            ['automatic'],
            DB::table('vehicles')->distinct()->orderBy('transmission')->pluck('transmission')->all(),
        );
    }

    public function test_the_showroom_and_the_fleet_end_up_agreeing(): void
    {
        // The state that made a filter built on one silently miss the other.
        $this->vehicle(['vehicle_type' => 'suv', 'fuel_type' => 'diesel', 'transmission' => 'automatic']);
        $this->listing(['body_type' => 'SUV', 'fuel_type' => 'Diesel', 'transmission' => 'Automatic']);

        $this->runNormalisation();

        $vehicle = DB::table('vehicles')->first();
        $listing = DB::table('vehicle_listings')->first();

        $this->assertSame($vehicle->vehicle_type, $listing->body_type);
        $this->assertSame($vehicle->fuel_type, $listing->fuel_type);
        $this->assertSame($vehicle->transmission, $listing->transmission);
    }

    /** Prose spellings map onto the storage keys the dropdowns now offer. */
    public function test_known_aliases_are_rewritten(): void
    {
        $this->listing(['body_type' => 'Station Wagon', 'transmission' => 'Auto', 'fuel_type' => 'Gasoline']);
        $this->listing(['body_type' => 'Saloon', 'transmission' => 'Tiptronic', 'fuel_type' => 'Plug-in hybrid']);

        $this->runNormalisation();

        $rows = DB::table('vehicle_listings')->orderBy('id')->get();

        $this->assertSame('wagon', $rows[0]->body_type);
        $this->assertSame('automatic', $rows[0]->transmission);
        $this->assertSame('petrol', $rows[0]->fuel_type);

        $this->assertSame('sedan', $rows[1]->body_type);
        $this->assertSame('semi_automatic', $rows[1]->transmission);
        $this->assertSame('plugin_hybrid', $rows[1]->fuel_type);
    }

    /** A spelling nobody anticipated becomes a key rather than being discarded. */
    public function test_an_unrecognised_value_is_keyed_not_dropped(): void
    {
        $this->listing(['body_type' => 'Armoured Personnel Carrier']);

        $this->runNormalisation();

        $this->assertSame(
            'armoured_personnel_carrier',
            DB::table('vehicle_listings')->value('body_type'),
        );
    }

    /**
     * Condition is deliberately left alone: "good" does not say whether a
     * vehicle was imported or bought locally, and that is the distinction the
     * new list draws. Guessing would put a claim in front of a buyer that
     * nobody at PISFA made.
     */
    public function test_condition_is_never_guessed_at(): void
    {
        $this->vehicle(['condition' => 'good']);
        $this->listing(['condition' => 'Used']);

        $this->runNormalisation();

        $this->assertSame('good', DB::table('vehicles')->value('condition'));
        $this->assertSame('Used', DB::table('vehicle_listings')->value('condition'));
    }

    public function test_running_it_twice_changes_nothing_the_second_time(): void
    {
        $this->vehicle(['fuel_type' => 'Diesel']);
        $this->listing(['body_type' => 'Station Wagon']);

        $this->runNormalisation();
        $first = [DB::table('vehicles')->value('fuel_type'), DB::table('vehicle_listings')->value('body_type')];

        $this->runNormalisation();
        $second = [DB::table('vehicles')->value('fuel_type'), DB::table('vehicle_listings')->value('body_type')];

        $this->assertSame($first, $second);
        $this->assertSame(['diesel', 'wagon'], $second);
    }

    public function test_blank_and_missing_values_are_left_as_they_are(): void
    {
        $this->vehicle(['drive_type' => null]);
        $this->listing(['drive_type' => null, 'body_type' => '']);

        $this->runNormalisation();

        $this->assertNull(DB::table('vehicles')->value('drive_type'));
        $this->assertSame('', DB::table('vehicle_listings')->value('body_type'));
    }

    /**
     * The normaliser itself, not the migration wrapping it.
     *
     * Calling it directly is what makes this testable at all: logic sealed
     * inside an anonymous migration class can only be run by the migrator, and
     * by then it has already run once against a database with no rows in it.
     */
    private function runNormalisation(): void
    {
        (new VehicleSpecificationNormaliser)->run();
    }

    /** @param array<string, mixed> $attributes */
    private function vehicle(array $attributes): Vehicle
    {
        return Vehicle::factory()->create($attributes);
    }

    /** @param array<string, mixed> $attributes */
    private function listing(array $attributes): VehicleListing
    {
        return VehicleListing::factory()->create($attributes);
    }
}
