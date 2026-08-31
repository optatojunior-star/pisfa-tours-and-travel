<?php

namespace Tests\Feature\Tours;

use App\Enums\TourBookingStatus;
use App\Enums\TourPackageStatus;
use App\Models\TourCategory;
use App\Models\TourPackage;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Tests\Feature\Tours\Concerns\BuildsTourFixtures;
use Tests\TestCase;

class CatalogueHttpTest extends TestCase
{
    use BuildsTourFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo('2026-08-20 09:00:00');
    }

    public function test_catalogue_only_lists_published_packages_in_active_categories_after_publication_time(): void
    {
        $activeCategory = TourCategory::factory()->create(['is_active' => true]);
        $inactiveCategory = TourCategory::factory()->create(['is_active' => false]);
        $visible = $this->publishedTour(['name' => 'Visible Safari'], $activeCategory);
        TourPackage::factory()->for($activeCategory, 'category')->create([
            'name' => 'Draft Safari',
            'status' => TourPackageStatus::Draft,
            'published_at' => null,
        ]);
        TourPackage::factory()->published()->for($activeCategory, 'category')->create([
            'name' => 'Future Safari',
            'published_at' => now()->addMinute(),
        ]);
        TourPackage::factory()->archived()->for($activeCategory, 'category')->create([
            'name' => 'Archived Safari',
        ]);
        TourPackage::factory()->published()->for($inactiveCategory, 'category')->create([
            'name' => 'Inactive Category Safari',
        ]);

        $this->get(route('tours.index'))
            ->assertOk()
            ->assertViewHas('packages', function (LengthAwarePaginator $packages) use ($visible): bool {
                return $packages->getCollection()->modelKeys() === [$visible->getKey()];
            })
            ->assertSee('Visible Safari')
            ->assertDontSee('Draft Safari')
            ->assertDontSee('Future Safari')
            ->assertDontSee('Archived Safari')
            ->assertDontSee('Inactive Category Safari');
    }

    public function test_show_route_returns_404_for_every_non_public_package_state(): void
    {
        $activeCategory = TourCategory::factory()->create(['is_active' => true]);
        $inactiveCategory = TourCategory::factory()->create(['is_active' => false]);
        $visible = $this->publishedTour(['name' => 'Public Tour'], $activeCategory);
        $hidden = [
            TourPackage::factory()->for($activeCategory, 'category')->create([
                'status' => TourPackageStatus::Draft,
                'published_at' => null,
            ]),
            TourPackage::factory()->published()->for($activeCategory, 'category')->create([
                'published_at' => now()->addHour(),
            ]),
            TourPackage::factory()->archived()->for($activeCategory, 'category')->create(),
            TourPackage::factory()->published()->for($inactiveCategory, 'category')->create(),
        ];

        $this->get(route('tours.show', $visible))
            ->assertOk()
            ->assertSee('Public Tour');

        foreach ($hidden as $package) {
            $this->get(route('tours.show', $package))->assertNotFound();
        }
    }

    public function test_catalogue_search_category_date_party_duration_and_exact_price_filters(): void
    {
        $safariCategory = TourCategory::factory()->create([
            'name' => 'Safaris',
            'slug' => 'safaris',
        ]);
        $cityCategory = TourCategory::factory()->create([
            'name' => 'City Tours',
            'slug' => 'city-tours',
        ]);
        $target = $this->publishedTour([
            'name' => 'Queen Elizabeth Wildlife Trail',
            'destination' => 'Kasese',
            'summary' => 'Lions, lakes, and open savannah.',
            'duration_days' => 5,
            'base_price_minor' => 19_995,
            'currency' => 'USD',
            'min_travelers' => 1,
            'max_travelers' => 4,
        ], $safariCategory);
        $targetDeparture = $this->bookableDeparture($target, [
            'starts_at' => now()->toImmutable()->addDays(12)->setTime(7, 0),
            'ends_at' => now()->toImmutable()->addDays(17)->setTime(17, 0),
            'cancellation_cutoff_at' => now()->addDays(10),
            'capacity' => 4,
        ]);
        $city = $this->publishedTour([
            'name' => 'Kampala City Highlights',
            'destination' => 'Kampala',
            'duration_days' => 1,
            'base_price_minor' => 8_000,
            'currency' => 'USD',
            'max_travelers' => 2,
        ], $cityCategory);
        $this->bookableDeparture($city, [
            'starts_at' => now()->toImmutable()->addDays(3)->setTime(8, 0),
            'ends_at' => now()->toImmutable()->addDays(3)->setTime(17, 0),
            'cancellation_cutoff_at' => now()->addDays(2),
        ]);
        $soldOut = $this->publishedTour([
            'name' => 'Sold Out Safari',
            'duration_days' => 5,
            'base_price_minor' => 18_000,
            'currency' => 'USD',
            'max_travelers' => 4,
        ], $safariCategory);
        $soldOutDeparture = $this->bookableDeparture($soldOut, [
            'starts_at' => $targetDeparture->starts_at,
            'ends_at' => $targetDeparture->ends_at,
            'cancellation_cutoff_at' => $targetDeparture->cancellation_cutoff_at,
            'capacity' => 2,
        ]);
        $this->persistedBooking(
            $this->customer(),
            $soldOutDeparture,
            TourBookingStatus::Confirmed,
            2,
        );

        $this->assertCatalogueIds(['q' => 'Queen Elizabeth'], [$target->getKey()]);
        $this->assertCatalogueIds(['category' => 'city-tours'], [$city->getKey()]);
        $this->assertCatalogueIds(['date' => $targetDeparture->starts_at->format('Y-m-d')], [
            $soldOut->getKey(),
            $target->getKey(),
        ]);
        $this->assertCatalogueIds(['party_size' => 3], [$target->getKey()]);
        $this->assertCatalogueIds(['duration_min' => 4, 'duration_max' => 6], [
            $soldOut->getKey(),
            $target->getKey(),
        ]);
        $this->assertCatalogueIds([
            'currency' => 'USD',
            'min_price' => '199.95',
            'max_price' => '199.95',
        ], [$target->getKey()]);
        $this->assertCatalogueIds(['sort' => 'price_asc', 'currency' => 'USD'], [
            $city->getKey(),
            $soldOut->getKey(),
            $target->getKey(),
        ]);
    }

    public function test_earliest_sort_and_cards_use_the_first_departure_that_is_truly_bookable_for_the_filters(): void
    {
        $candidatePackage = $this->publishedTour([
            'name' => 'Filtered Candidate Tour',
            'currency' => 'USD',
            'base_price_minor' => 90_000,
            'min_travelers' => 2,
            'max_travelers' => 6,
        ]);
        $expiredCutoff = $this->bookableDeparture($candidatePackage, [
            'starts_at' => now()->toImmutable()->addDays(3),
            'ends_at' => now()->toImmutable()->addDays(4),
            'cancellation_cutoff_at' => now()->subMinute(),
            'capacity' => 6,
            'price_override_minor' => 11_111,
            'currency' => 'USD',
        ]);
        $insufficient = $this->bookableDeparture($candidatePackage, [
            'starts_at' => now()->toImmutable()->addDays(9),
            'ends_at' => now()->toImmutable()->addDays(10),
            'cancellation_cutoff_at' => now()->addDays(8),
            'capacity' => 5,
            'price_override_minor' => 22_222,
            'currency' => 'USD',
        ]);
        $this->persistedBooking(
            $this->customer(),
            $insufficient,
            TourBookingStatus::Confirmed,
            4,
        );
        $bookable = $this->bookableDeparture($candidatePackage, [
            'starts_at' => now()->toImmutable()->addDays(10),
            'ends_at' => now()->toImmutable()->addDays(11),
            'cancellation_cutoff_at' => now()->addDays(8),
            'capacity' => 6,
            'price_override_minor' => 33_333,
            'currency' => 'USD',
        ]);
        $this->persistedBooking(
            $this->customer(),
            $bookable,
            TourBookingStatus::Confirmed,
            1,
        );

        $earlierPackage = $this->publishedTour([
            'name' => 'Earlier Bookable Tour',
            'currency' => 'USD',
            'min_travelers' => 2,
            'max_travelers' => 6,
        ]);
        $earlierBookable = $this->bookableDeparture($earlierPackage, [
            'starts_at' => now()->toImmutable()->addDays(7),
            'ends_at' => now()->toImmutable()->addDays(8),
            'cancellation_cutoff_at' => now()->addDays(6),
            'capacity' => 6,
            'price_override_minor' => 55_555,
            'currency' => 'USD',
        ]);

        $unavailablePackage = $this->publishedTour([
            'name' => 'No Bookable Tour',
            'currency' => 'USD',
            'min_travelers' => 2,
            'max_travelers' => 6,
        ]);
        $this->bookableDeparture($unavailablePackage, [
            'starts_at' => now()->toImmutable()->addDays(2),
            'ends_at' => now()->toImmutable()->addDays(3),
            'cancellation_cutoff_at' => now()->subMinute(),
            'capacity' => 6,
            'price_override_minor' => 66_666,
            'currency' => 'USD',
        ]);

        $response = $this->get(route('tours.index', ['sort' => 'earliest']))->assertOk();
        /** @var LengthAwarePaginator $packages */
        $packages = $response->viewData('packages');
        $this->assertSame([
            $earlierPackage->getKey(),
            $candidatePackage->getKey(),
            $unavailablePackage->getKey(),
        ], $packages->getCollection()->modelKeys());
        $this->assertTrue($earlierBookable->starts_at->equalTo(CarbonImmutable::parse(
            (string) $packages->getCollection()->firstWhere('id', $earlierPackage->getKey())->next_departure_at,
        )));
        $this->assertTrue($bookable->starts_at->equalTo(CarbonImmutable::parse(
            (string) $packages->getCollection()->firstWhere('id', $candidatePackage->getKey())->next_departure_at,
        )));
        $this->assertNull(
            $packages->getCollection()->firstWhere('id', $unavailablePackage->getKey())->next_departure_at,
        );
        $response
            ->assertSeeInOrder(['Earlier Bookable Tour', 'Filtered Candidate Tour', 'No Bookable Tour'])
            ->assertSee(Money::format(33_333, 'USD'))
            ->assertDontSee(Money::format(11_111, 'USD'))
            ->assertDontSee(Money::format(22_222, 'USD'));

        $selectedDate = now(config('pisfa.business_timezone'))->addDays(8)->toDateString();
        $filtered = $this->get(route('tours.index', [
            'date' => $selectedDate,
            'party_size' => 4,
            'sort' => 'earliest',
        ]))->assertOk();
        /** @var LengthAwarePaginator $filteredPackages */
        $filteredPackages = $filtered->viewData('packages');
        $this->assertSame([$candidatePackage->getKey()], $filteredPackages->getCollection()->modelKeys());
        $selectedPackage = $filteredPackages->getCollection()->sole();
        $this->assertSame([$bookable->getKey()], $selectedPackage->departures->modelKeys());
        $this->assertTrue($bookable->starts_at->equalTo(CarbonImmutable::parse(
            (string) $selectedPackage->next_departure_at,
        )));
        $filtered
            ->assertSee(Money::format(33_333, 'USD'))
            ->assertSee('datetime="'.$bookable->starts_at->toDateString().'"', false)
            ->assertDontSee(Money::format(22_222, 'USD'));

        $this->assertNotSame($expiredCutoff->getKey(), $bookable->getKey());
    }

    public function test_invalid_catalogue_filters_fail_validation_without_executing_unsafe_queries(): void
    {
        $this->from(route('tours.index'))
            ->get(route('tours.index', [
                'date' => 'not-a-date',
                'party_size' => 0,
                'duration_min' => 10,
                'duration_max' => 2,
                'min_price' => '1,000.00',
                'currency' => 'EUR',
                'sort' => 'raw-sql',
                'per_page' => 5000,
            ]))
            ->assertRedirect(route('tours.index'))
            ->assertSessionHasErrors([
                'date',
                'party_size',
                'duration_max',
                'min_price',
                'currency',
                'sort',
                'per_page',
            ]);
    }

    public function test_oversized_public_price_filter_returns_validation_redirect_instead_of_server_error(): void
    {
        $this->from(route('tours.index'))
            ->get(route('tours.index', [
                'currency' => 'USD',
                'min_price' => '999999999999999999999999999999999999.99',
            ]))
            ->assertRedirect(route('tours.index'))
            ->assertSessionHasErrors('min_price');
    }

    public function test_price_sort_requires_one_currency_before_comparing_minor_units(): void
    {
        $this->from(route('tours.index'))
            ->get(route('tours.index', ['sort' => 'price_asc']))
            ->assertRedirect(route('tours.index'))
            ->assertSessionHasErrors('currency');
    }

    public function test_base_price_drives_price_filter_sort_and_primary_card_price_while_override_is_secondary(): void
    {
        $baseCheaper = $this->publishedTour([
            'name' => 'Base Price First',
            'base_price_minor' => 10_000,
            'currency' => 'USD',
        ]);
        $this->bookableDeparture($baseCheaper, [
            'price_override_minor' => 90_000,
            'currency' => 'USD',
        ]);
        $baseCostlier = $this->publishedTour([
            'name' => 'Base Price Second',
            'base_price_minor' => 20_000,
            'currency' => 'USD',
        ]);
        $this->bookableDeparture($baseCostlier, [
            'price_override_minor' => 5_000,
            'currency' => 'USD',
        ]);

        $this->assertCatalogueIds([
            'sort' => 'price_asc',
            'currency' => 'USD',
        ], [$baseCheaper->getKey(), $baseCostlier->getKey()]);

        $this->get(route('tours.index', [
            'currency' => 'USD',
            'min_price' => '100.00',
            'max_price' => '100.00',
            'sort' => 'price_asc',
        ]))
            ->assertOk()
            ->assertViewHas('packages', function (LengthAwarePaginator $packages) use ($baseCheaper): bool {
                return $packages->getCollection()->modelKeys() === [$baseCheaper->getKey()];
            })
            ->assertSeeInOrder([
                'Package base price, per traveler',
                Money::format(10_000, 'USD'),
                'Next departure: '.Money::format(90_000, 'USD'),
            ])
            ->assertDontSee(Money::format(5_000, 'USD'));
    }

    /** @param array<string, mixed> $filters
     * @param  list<int>  $expectedIds
     */
    private function assertCatalogueIds(array $filters, array $expectedIds): void
    {
        $this->get(route('tours.index', $filters))
            ->assertOk()
            ->assertViewHas('packages', function (LengthAwarePaginator $packages) use ($expectedIds): bool {
                return $packages->getCollection()->modelKeys() === $expectedIds;
            });
    }
}
