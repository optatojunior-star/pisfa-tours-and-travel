<?php

namespace Tests\Feature\CarHire;

use App\Enums\VehicleCatalogueStatus;
use App\Models\Vehicle;
use App\Support\VehicleComparison;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\CarHire\Concerns\BuildsCarHireFixtures;
use Tests\TestCase;

/**
 * Two to four hire vehicles, side by side.
 *
 * The selection lives in the query string rather than in the browser, because
 * the person choosing the vehicle and the person approving the cost are usually
 * not the same person — so these tests care as much about the address being
 * shareable and safe as about the table being right.
 */
class VehicleComparisonTest extends TestCase
{
    use BuildsCarHireFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo('2026-08-20 09:00:00');
    }

    public function test_a_visitor_compares_two_bookable_vehicles(): void
    {
        [$prado] = $this->bookableVehicle([
            'make' => 'Toyota', 'model' => 'Prado', 'slug' => 'toyota-prado',
            'drive_type' => '4wd', 'engine_cc' => 3000, 'seating_capacity' => 7,
        ]);
        [$hiace] = $this->bookableVehicle([
            'make' => 'Toyota', 'model' => 'Hiace', 'slug' => 'toyota-hiace',
            'drive_type' => '2wd', 'engine_cc' => 2500, 'seating_capacity' => 14,
        ], ['self_drive_daily_minor' => 250_000]);

        $this->get(route('car-hire.compare', ['vehicles' => [$prado->slug, $hiace->slug]]))
            ->assertOk()
            ->assertSee('Comparing 2 vehicles')
            ->assertSee('Prado')
            ->assertSee('Hiace')
            ->assertSee('4WD')
            ->assertSee('14');
    }

    public function test_the_columns_follow_the_order_the_visitor_ticked_them(): void
    {
        [$first] = $this->bookableVehicle(['make' => 'Alpha', 'model' => 'One', 'slug' => 'alpha-one']);
        [$second] = $this->bookableVehicle(['make' => 'Zulu', 'model' => 'Two', 'slug' => 'zulu-two']);

        // Reverse alphabetical: if the page were ordering by anything of its
        // own, Alpha would come first and this would fail.
        $this->get(route('car-hire.compare', ['vehicles' => [$second->slug, $first->slug]]))
            ->assertOk()
            ->assertSeeInOrder(['Zulu Two', 'Alpha One']);
    }

    /**
     * The point of a comparison is the differences. A row that reads the same in
     * every column is noise, and burying the two rows that matter under eleven
     * that do not is how a comparison stops being useful.
     */
    public function test_rows_that_differ_are_marked_and_sorted_first(): void
    {
        [$a] = $this->bookableVehicle(['slug' => 'a-one', 'seating_capacity' => 5, 'drive_type' => '2wd']);
        [$b] = $this->bookableVehicle(['slug' => 'b-two', 'seating_capacity' => 5, 'drive_type' => '4wd']);

        $vehicles = Vehicle::query()
            ->whereIn('slug', [$a->slug, $b->slug])
            ->with('bookableHireRates')
            ->orderBy('slug')
            ->get();

        $rows = VehicleComparison::rows($vehicles);
        $labels = array_column($rows, 'label');
        $byLabel = collect($rows)->keyBy('label');

        $this->assertTrue($byLabel['Drive']['differs'], 'A 2WD and a 4WD were not reported as differing.');
        $this->assertFalse($byLabel['Seats']['differs'], 'Two five-seaters were reported as differing.');

        $this->assertLessThan(
            array_search('Seats', $labels, true),
            array_search('Drive', $labels, true),
            'A differing row was sorted below an identical one.',
        );
    }

    public function test_one_vehicle_is_not_a_comparison(): void
    {
        [$only] = $this->bookableVehicle(['slug' => 'lonely-one']);

        $this->get(route('car-hire.compare', ['vehicles' => [$only->slug]]))
            ->assertSessionHasErrors('vehicles');
    }

    public function test_more_than_the_maximum_is_refused(): void
    {
        $slugs = [];

        foreach (range(1, 5) as $index) {
            [$vehicle] = $this->bookableVehicle(['slug' => 'many-'.$index]);
            $slugs[] = $vehicle->slug;
        }

        $this->get(route('car-hire.compare', ['vehicles' => $slugs]))
            ->assertSessionHasErrors('vehicles');
    }

    /**
     * The comparison is a public address anyone can edit. It must obey exactly
     * the same visibility rule as the catalogue it was reached from — otherwise
     * it becomes a way to read draft stock by guessing a slug.
     */
    public function test_a_draft_vehicle_can_never_be_reached_through_a_hand_edited_address(): void
    {
        [$published] = $this->bookableVehicle(['slug' => 'published-one']);

        $draft = Vehicle::factory()->create([
            'slug' => 'secret-draft',
            'catalogue_status' => VehicleCatalogueStatus::Draft,
        ]);
        $this->currentRate($draft);

        // Only one vehicle survives the visibility filter, and one is not a
        // comparison — so this 404s rather than quietly showing a single car.
        $this->get(route('car-hire.compare', ['vehicles' => [$published->slug, $draft->slug]]))
            ->assertNotFound();
    }

    public function test_an_unpriced_vehicle_is_not_comparable(): void
    {
        [$priced] = $this->bookableVehicle(['slug' => 'priced-one']);
        $unpriced = $this->publishedVehicle(['slug' => 'unpriced-one']);

        $this->assertTrue($unpriced->exists);

        $this->get(route('car-hire.compare', ['vehicles' => [$priced->slug, $unpriced->slug]]))
            ->assertNotFound();
    }

    public function test_an_expired_price_does_not_make_a_vehicle_comparable(): void
    {
        [$priced] = $this->bookableVehicle(['slug' => 'current-price']);
        [$stale] = $this->bookableVehicle(
            ['slug' => 'stale-price'],
            ['effective_from' => now()->subYear(), 'effective_until' => now()->subDay()],
        );

        $this->assertTrue($stale->exists);

        $this->get(route('car-hire.compare', ['vehicles' => [$priced->slug, $stale->slug]]))
            ->assertNotFound();
    }

    public function test_the_hire_window_survives_the_trip_through_the_comparison(): void
    {
        [$a] = $this->bookableVehicle(['slug' => 'window-a']);
        [$b] = $this->bookableVehicle(['slug' => 'window-b']);

        // Somebody who already chose their dates should not have to choose them
        // again because they compared two vehicles on the way.
        $this->get(route('car-hire.compare', [
            'vehicles' => [$a->slug, $b->slug],
            'pickup_at' => '2026-09-01T09:00',
            'return_at' => '2026-09-05T09:00',
            'hire_mode' => 'self_drive',
            'currency' => 'UGX',
        ]))
            ->assertOk()
            ->assertSee('pickup_at=2026-09-01T09%3A00', false);
    }

    public function test_the_catalogue_offers_a_drive_filter_and_applies_it(): void
    {
        [$fourByFour] = $this->bookableVehicle([
            'make' => 'Rugged', 'model' => 'Cruiser', 'slug' => 'rugged-cruiser', 'drive_type' => '4wd',
        ]);
        [$twoWheel] = $this->bookableVehicle([
            'make' => 'Townish', 'model' => 'Saloon', 'slug' => 'townish-saloon', 'drive_type' => '2wd',
        ]);

        $this->assertTrue($fourByFour->exists && $twoWheel->exists);

        $this->get(route('car-hire.index', ['drive_type' => '4wd']))
            ->assertOk()
            ->assertSee('Rugged')
            ->assertDontSee('Townish');
    }

    public function test_an_invented_drive_type_is_rejected_rather_than_ignored(): void
    {
        $this->get(route('car-hire.index', ['drive_type' => 'hovercraft']))
            ->assertSessionHasErrors('drive_type');
    }
}
