<?php

namespace Tests\Feature\Seeding;

use App\Models\Post;
use App\Models\Property;
use App\Models\PropertyRoomRate;
use App\Models\PropertyRoomType;
use App\Models\TourDeparture;
use App\Models\TourPackage;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleHireRate;
use App\Models\VehicleListing;
use Database\Seeders\DemoCatalogueSeeder;
use Database\Seeders\DemoContentSeeder;
use Database\Seeders\DemoRoleUserSeeder;
use Database\Seeders\TourCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The demo seeders must be safe to run twice, and safe to run against a
 * database that already holds real records.
 *
 * That is not a nicety. "Never use migrate:fresh or destructive seeders in
 * production" is a standing rule for this project, and the only way to keep it
 * is to prove the seeders are additive rather than to remember that they are.
 */
class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    private function seedEverything(): void
    {
        $this->seed(TourCategorySeeder::class);
        $this->seed(DemoRoleUserSeeder::class);
        $this->seed(DemoCatalogueSeeder::class);
        $this->seed(DemoContentSeeder::class);
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        return [
            'tours' => TourPackage::query()->count(),
            'departures' => TourDeparture::query()->count(),
            'vehicles' => Vehicle::query()->count(),
            'hire_rates' => VehicleHireRate::query()->count(),
            'properties' => Property::query()->count(),
            'room_types' => PropertyRoomType::query()->count(),
            'room_rates' => PropertyRoomRate::query()->count(),
            'posts' => Post::query()->count(),
            'listings' => VehicleListing::query()->count(),
        ];
    }

    public function test_the_seeders_produce_a_catalogue_somebody_could_browse(): void
    {
        $this->seedEverything();

        $counts = $this->counts();

        foreach ($counts as $what => $count) {
            $this->assertGreaterThan(0, $count, "Nothing was seeded for {$what}.");
        }
    }

    public function test_running_the_seeders_twice_changes_nothing(): void
    {
        $this->seedEverything();
        $before = $this->counts();

        $this->seedEverything();
        $after = $this->counts();

        $this->assertSame($before, $after, 'A second run of the demo seeders created duplicates.');
    }

    public function test_the_seeders_never_remove_existing_records(): void
    {
        // A record that looks like something a real deployment already had.
        $existing = TourPackage::factory()->create(['slug' => 'a-real-tour-somebody-created']);

        $this->seedEverything();

        $this->assertDatabaseHas('tour_packages', ['slug' => 'a-real-tour-somebody-created']);
        $this->assertNotNull($existing->fresh(), 'The seeders deleted a record they did not create.');
    }

    public function test_every_room_type_has_a_rate_so_it_is_bookable(): void
    {
        $this->seedEverything();

        $withoutRate = PropertyRoomType::query()
            ->whereDoesntHave('rates')
            ->pluck('name')
            ->all();

        $this->assertSame([], $withoutRate, 'These room types cannot be booked: they have no rate.');
    }

    public function test_every_published_tour_has_a_future_departure(): void
    {
        $this->seedEverything();

        // Dates are derived from now(), so a demo database seeded months ago
        // still has something bookable rather than a page of past dates.
        $withoutDeparture = TourPackage::query()
            ->whereDoesntHave('departures', fn ($query) => $query->where('starts_at', '>=', now()))
            ->pluck('name')
            ->all();

        $this->assertSame([], $withoutDeparture, 'These tours have nothing bookable.');
    }

    public function test_every_hireable_vehicle_has_a_rate(): void
    {
        $this->seedEverything();

        $withoutRate = Vehicle::query()
            ->whereDoesntHave('hireRates')
            ->pluck('slug')
            ->all();

        $this->assertSame([], $withoutRate, 'These vehicles cannot be hired: they have no rate.');
    }

    public function test_the_demo_seeders_refuse_to_run_in_production(): void
    {
        // Demo bookings in a real ledger are indistinguishable from real ones a
        // month later.
        //
        // The seeders are invoked directly rather than through `db:seed`,
        // because that command puts up its own production confirmation prompt
        // first. That prompt is a second layer of protection and a good thing —
        // but it would stop this test before it reached the guard it is about.
        $this->app->detectEnvironment(fn (): string => 'production');

        foreach ([DemoCatalogueSeeder::class, DemoContentSeeder::class, DemoRoleUserSeeder::class] as $class) {
            $seeder = new $class;
            $seeder->setContainer($this->app);
            $seeder->run();
        }

        $this->assertSame(0, TourPackage::query()->count());
        $this->assertSame(0, VehicleListing::query()->count());
        $this->assertSame(0, Post::query()->count());
        $this->assertSame(0, User::query()->count());
    }

    public function test_the_seeded_catalogue_appears_on_the_public_site(): void
    {
        $this->withoutVite();
        $this->seedEverything();

        $this->get(route('tours.index'))->assertOk()->assertSee('Gorilla Trekking in Bwindi');
        $this->get(route('car-hire.index'))->assertOk()->assertSee('Land Cruiser Prado');
        $this->get(route('accommodation.index'))->assertOk()->assertSee('Buhoma Forest Lodge');
        $this->get(route('showroom.index'))->assertOk()->assertSee('Land Cruiser Prado TX');
        $this->get(route('blog.index'))->assertOk()->assertSee('When to visit Uganda');
    }

    public function test_seeded_money_is_stored_in_minor_units(): void
    {
        $this->seedEverything();

        $tour = TourPackage::query()->where('slug', 'gorilla-trekking-bwindi-3-days')->firstOrFail();

        // UGX has no minor unit, so 5,400,000 shillings is 5400000 — not
        // 540000000. Getting this wrong by a factor of a hundred is the classic
        // seeded-money bug and it looks plausible on the page.
        $this->assertSame(5_400_000, $tour->base_price_minor);
        $this->assertSame('UGX', $tour->currency);
    }
}
