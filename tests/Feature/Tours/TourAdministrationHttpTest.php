<?php

namespace Tests\Feature\Tours;

use App\Enums\AccountStatus;
use App\Enums\TourBookingStatus;
use App\Enums\TourDepartureStatus;
use App\Enums\TourPackageItemType;
use App\Enums\TourPackageStatus;
use App\Models\TourCategory;
use App\Models\TourDeparture;
use App\Models\TourPackage;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Tours\Concerns\BuildsTourFixtures;
use Tests\TestCase;

class TourAdministrationHttpTest extends TestCase
{
    use BuildsTourFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo('2026-08-20 09:00:00');
    }

    public function test_staff_can_render_every_administration_workspace_route(): void
    {
        $staff = $this->operationsUser();
        $package = $this->publishedTour(['name' => 'Administration Smoke Tour']);
        $departure = $this->bookableDeparture($package);
        $booking = $this->persistedBooking(
            $this->customer(),
            $departure,
            TourBookingStatus::Confirmed,
        );

        foreach ([
            route('admin.tours.index'),
            route('admin.tours.create'),
            route('admin.tours.show', $package),
            route('admin.tours.edit', $package),
            route('admin.tour-categories.index'),
            route('admin.tour-bookings.index'),
            route('admin.tour-bookings.show', $booking),
        ] as $url) {
            $this->actingAs($staff)
                ->get($url)
                ->assertOk()
                ->assertSee('name="viewport"', false);
        }
    }

    public function test_admin_booking_show_only_lists_active_verified_drivers_for_assignment(): void
    {
        $staff = $this->operationsUser();
        $eligible = $this->driver(['name' => 'Eligible Driver']);
        $inactive = $this->driver([
            'name' => 'Inactive Driver',
            'status' => AccountStatus::Inactive,
        ]);
        $unverified = $this->driver([
            'name' => 'Unverified Driver',
            'email_verified_at' => null,
        ]);
        $booking = $this->persistedBooking(
            $this->customer(),
            $this->bookableDeparture(),
            TourBookingStatus::Confirmed,
        );

        $response = $this->actingAs($staff)
            ->get(route('admin.tour-bookings.show', $booking))
            ->assertOk()
            ->assertSee('Eligible Driver')
            ->assertDontSee('Inactive Driver')
            ->assertDontSee('Unverified Driver');

        $this->assertSame([$eligible->getKey()], $response->viewData('drivers')->modelKeys());
        $this->assertNotSame($inactive->getKey(), $eligible->getKey());
        $this->assertNotSame($unverified->getKey(), $eligible->getKey());
    }

    public function test_admin_booking_show_hides_driver_assignment_after_the_departure_end(): void
    {
        $staff = $this->operationsUser();
        $endsAt = now()->toImmutable();
        $departure = $this->bookableDeparture(attributes: [
            'starts_at' => $endsAt->subDay(),
            'ends_at' => $endsAt,
            'cancellation_cutoff_at' => $endsAt->subDays(2),
        ]);
        $booking = $this->persistedBooking(
            $this->customer(),
            $departure,
            TourBookingStatus::Confirmed,
        );

        $this->actingAs($staff)
            ->get(route('admin.tour-bookings.show', $booking))
            ->assertOk()
            ->assertDontSee('id="driver-user"', false);
    }

    public function test_admin_booking_show_disables_confirmation_when_departure_is_not_future_and_scheduled(): void
    {
        $staff = $this->operationsUser();
        $pastStart = now()->toImmutable()->subMinute();
        $pastDeparture = $this->bookableDeparture(attributes: [
            'starts_at' => $pastStart,
            'ends_at' => $pastStart->addDay(),
            'cancellation_cutoff_at' => $pastStart->subDay(),
        ]);
        $closedDeparture = $this->bookableDeparture(attributes: [
            'status' => TourDepartureStatus::Closed,
        ]);

        foreach ([$pastDeparture, $closedDeparture] as $departure) {
            $booking = $this->persistedBooking($this->customer(), $departure);

            $this->actingAs($staff)
                ->get(route('admin.tour-bookings.show', $booking))
                ->assertOk()
                ->assertSee('Confirm booking')
                ->assertDontSee('name="status" value="confirmed"', false);
        }
    }

    /** @return array<string, array{string}> */
    public static function missingPublishContent(): array
    {
        return [
            'inactive category' => ['inactive-category'],
            'cover image' => ['cover'],
            'complete itinerary' => ['itinerary'],
            'inclusions' => ['inclusions'],
            'exclusions' => ['exclusions'],
        ];
    }

    #[DataProvider('missingPublishContent')]
    public function test_publish_endpoint_rejects_each_missing_customer_facing_prerequisite(string $missing): void
    {
        $staff = $this->operationsUser();
        $package = $this->completeDraftPackage();

        match ($missing) {
            'inactive-category' => $package->category->update(['is_active' => false]),
            'cover' => $package->media()->delete(),
            'itinerary' => $package->itineraryDays()->where('day_number', 2)->delete(),
            'inclusions' => $package->inclusions()->delete(),
            'exclusions' => $package->exclusions()->delete(),
        };

        $url = route('admin.tours.show', $package);
        $this->actingAs($staff)
            ->from($url)
            ->patch(route('admin.tours.publish', $package))
            ->assertRedirect($url)
            ->assertSessionHasErrors();

        $this->assertSame(TourPackageStatus::Draft, $package->fresh()->status);
        $this->assertDatabaseMissing('audit_logs', [
            'event' => 'tour_package.published',
            'auditable_id' => $package->getKey(),
        ]);
    }

    public function test_complete_draft_can_be_published_and_is_audited(): void
    {
        $staff = $this->operationsUser();
        $package = $this->completeDraftPackage();

        $this->actingAs($staff)
            ->from(route('admin.tours.show', $package))
            ->patch(route('admin.tours.publish', $package))
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $this->assertSame(TourPackageStatus::Published, $package->fresh()->status);
        $this->assertNotNull($package->fresh()->published_at);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'tour_package.published',
            'auditable_id' => $package->getKey(),
            'user_id' => $staff->getKey(),
        ]);
    }

    public function test_archived_package_cannot_be_published_and_can_be_restored_to_an_audited_draft(): void
    {
        $staff = $this->operationsUser();
        $package = $this->completeDraftPackage();
        $publishedAt = now()->subDays(5)->startOfSecond();
        $package->forceFill([
            'status' => TourPackageStatus::Archived,
            'published_at' => $publishedAt,
        ])->save();
        $url = route('admin.tours.show', $package);

        $this->actingAs($staff)
            ->from($url)
            ->patch(route('admin.tours.publish', $package))
            ->assertRedirect($url)
            ->assertSessionHasErrors('status');

        $package->refresh();
        $this->assertSame(TourPackageStatus::Archived, $package->status);
        $this->assertTrue($publishedAt->equalTo($package->published_at));
        $this->assertDatabaseMissing('audit_logs', [
            'event' => 'tour_package.published',
            'auditable_id' => $package->getKey(),
        ]);

        $this->actingAs($staff)
            ->from($url)
            ->patch(route('admin.tours.restore', $package))
            ->assertRedirect($url)
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $package->refresh();
        $this->assertSame(TourPackageStatus::Draft, $package->status);
        $this->assertNull($package->published_at);
        $this->assertSame($staff->getKey(), $package->updated_by_user_id);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'tour_package.restored_to_draft',
            'auditable_id' => $package->getKey(),
            'user_id' => $staff->getKey(),
        ]);
    }

    public function test_ordinary_package_edit_preserves_its_current_published_status(): void
    {
        $staff = $this->operationsUser();
        $package = $this->completeDraftPackage();
        $publishedAt = now()->subDay()->startOfSecond();
        $package->forceFill([
            'status' => TourPackageStatus::Published,
            'published_at' => $publishedAt,
        ])->save();

        $this->actingAs($staff)
            ->patch(
                route('admin.tours.update', $package),
                $this->packageUpdatePayload($package, ['name' => 'Edited Published Safari']),
            )
            ->assertRedirect(route('admin.tours.show', $package))
            ->assertSessionHasNoErrors();

        $package->refresh();
        $this->assertSame('Edited Published Safari', $package->name);
        $this->assertSame(TourPackageStatus::Published, $package->status);
        $this->assertTrue($publishedAt->equalTo($package->published_at));
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'tour_package.updated',
            'auditable_id' => $package->getKey(),
            'user_id' => $staff->getKey(),
        ]);
    }

    public function test_ordinary_departure_edit_preserves_its_current_closed_status(): void
    {
        $staff = $this->operationsUser();
        $departure = $this->bookableDeparture(attributes: [
            'status' => TourDepartureStatus::Closed,
        ]);
        $businessTimezone = config('pisfa.business_timezone');

        $this->actingAs($staff)
            ->patch(route('admin.tour-departures.update', [$departure->tourPackage, $departure]), [
                'starts_at' => $departure->starts_at->timezone($businessTimezone)->format('Y-m-d H:i:s'),
                'ends_at' => $departure->ends_at->timezone($businessTimezone)->format('Y-m-d H:i:s'),
                'cancellation_cutoff_at' => $departure->cancellation_cutoff_at->timezone($businessTimezone)->format('Y-m-d H:i:s'),
                'capacity' => $departure->capacity,
                'currency' => $departure->tourPackage->currency,
                'meeting_point' => 'Updated operations meeting point',
                'customer_notes' => 'Updated customer note.',
                'internal_notes' => 'Updated internal note.',
            ])
            ->assertRedirect(route('admin.tours.show', $departure->tourPackage))
            ->assertSessionHasNoErrors();

        $departure->refresh();
        $this->assertSame(TourDepartureStatus::Closed, $departure->status);
        $this->assertSame('Updated operations meeting point', $departure->meeting_point);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'tour_departure.updated',
            'auditable_id' => $departure->getKey(),
            'user_id' => $staff->getKey(),
        ]);
    }

    public function test_published_category_cannot_be_deactivated(): void
    {
        $staff = $this->operationsUser();
        $package = $this->publishedTour();
        $url = route('admin.tour-categories.index');

        $this->actingAs($staff)
            ->from($url)
            ->patch(route('admin.tour-categories.toggle', $package->category), ['is_active' => false])
            ->assertRedirect($url)
            ->assertSessionHasErrors('is_active');

        $this->assertTrue($package->category->fresh()->is_active);
    }

    public function test_departure_store_normalizes_local_dates_and_exact_override_price(): void
    {
        $staff = $this->operationsUser();
        $package = $this->publishedTour();

        $this->actingAs($staff)
            ->post(route('admin.tour-departures.store', $package), [
                'starts_at' => '2026-09-10 08:00:00',
                'ends_at' => '2026-09-12 18:00:00',
                'cancellation_cutoff_at' => '2026-09-08 08:00:00',
                'capacity' => 14,
                'price_override' => '425.75',
                'currency' => 'usd',
                'meeting_point' => 'PISFA Kampala office',
                'customer_notes' => 'Arrive 30 minutes early.',
                'internal_notes' => 'Confirm vehicle allocation.',
            ])
            ->assertRedirect(route('admin.tours.show', $package))
            ->assertSessionHasNoErrors();

        $departure = TourDeparture::query()->sole();
        $this->assertSame(42_575, $departure->price_override_minor);
        $this->assertSame('USD', $departure->currency);
        $this->assertSame('2026-09-10 05:00:00', $departure->starts_at->format('Y-m-d H:i:s'));
        $this->assertSame(14, $departure->capacity);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'tour_departure.created',
            'auditable_id' => $departure->getKey(),
            'user_id' => $staff->getKey(),
        ]);
    }

    public function test_administration_http_enforces_canonical_package_and_departure_caps(): void
    {
        $staff = $this->operationsUser();
        $category = TourCategory::factory()->create(['is_active' => true]);
        $payload = $this->newPackageFormPayload($category, [
            'name' => 'HTTP Boundary Tour',
            'slug' => 'http-boundary-tour',
            'duration_days' => 90,
            'max_travelers' => 50,
        ]);

        $this->actingAs($staff)
            ->post(route('admin.tours.store'), $payload)
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $package = TourPackage::query()->where('slug', 'http-boundary-tour')->sole();
        $this->assertSame(90, $package->duration_days);
        $this->assertSame(50, $package->max_travelers);

        foreach ([
            'duration_days' => ['duration_days' => 91],
            'min_travelers' => ['min_travelers' => 51, 'max_travelers' => 51],
            'max_travelers' => ['max_travelers' => 51],
        ] as $field => $invalid) {
            $invalidPayload = array_replace($payload, $invalid, [
                'name' => 'Invalid HTTP '.$field,
                'slug' => 'invalid-http-'.str($field)->slug(),
            ]);

            $this->actingAs($staff)
                ->from(route('admin.tours.create'))
                ->post(route('admin.tours.store'), $invalidPayload)
                ->assertRedirect(route('admin.tours.create'))
                ->assertSessionHasErrors($field);
        }

        $startsAt = now()->toImmutable()->addDays(30)->startOfHour();
        $departurePayload = [
            'starts_at' => $startsAt->timezone(config('pisfa.business_timezone'))->format('Y-m-d H:i:s'),
            'ends_at' => $startsAt->addDay()->timezone(config('pisfa.business_timezone'))->format('Y-m-d H:i:s'),
            'cancellation_cutoff_at' => $startsAt->subDay()->timezone(config('pisfa.business_timezone'))->format('Y-m-d H:i:s'),
            'capacity' => 500,
            'currency' => 'UGX',
        ];
        $this->actingAs($staff)
            ->post(route('admin.tour-departures.store', $package), $departurePayload)
            ->assertRedirect(route('admin.tours.show', $package))
            ->assertSessionHasNoErrors();
        $this->assertSame(500, TourDeparture::query()->sole()->capacity);

        $this->actingAs($staff)
            ->from(route('admin.tours.show', $package))
            ->post(route('admin.tour-departures.store', $package), array_replace($departurePayload, [
                'starts_at' => $startsAt->addDays(2)->timezone(config('pisfa.business_timezone'))->format('Y-m-d H:i:s'),
                'ends_at' => $startsAt->addDays(3)->timezone(config('pisfa.business_timezone'))->format('Y-m-d H:i:s'),
                'cancellation_cutoff_at' => $startsAt->addDay()->timezone(config('pisfa.business_timezone'))->format('Y-m-d H:i:s'),
                'capacity' => 501,
            ]))
            ->assertRedirect(route('admin.tours.show', $package))
            ->assertSessionHasErrors('capacity');
    }

    public function test_administration_http_uses_the_shared_public_media_url_contract(): void
    {
        $staff = $this->operationsUser();
        $category = TourCategory::factory()->create(['is_active' => true]);

        foreach ([
            'https' => 'https://cdn.example.test/tours/http-cover.jpg',
            'storage path' => '/storage/tours/http-cover.jpg',
        ] as $case => $url) {
            $slug = 'http-media-accepted-'.str($case)->slug();
            $payload = $this->newPackageFormPayload($category, [
                'name' => 'HTTP media accepted '.$case,
                'slug' => $slug,
            ]);
            $payload['media'][0]['url'] = $url;

            $this->actingAs($staff)
                ->post(route('admin.tours.store'), $payload)
                ->assertRedirect()
                ->assertSessionHasNoErrors();
            $this->assertDatabaseHas('tour_package_media', ['url' => $url]);
        }

        foreach ([
            'http' => 'http://cdn.example.test/tours/http-cover.jpg',
            'protocol relative' => '//cdn.example.test/tours/http-cover.jpg',
            'backslash' => '\\storage\\tours\\http-cover.jpg',
            'control character' => "/storage/tours/http-cover\nname.jpg",
        ] as $case => $url) {
            $slug = 'http-media-rejected-'.str($case)->slug();
            $payload = $this->newPackageFormPayload($category, [
                'name' => 'HTTP media rejected '.$case,
                'slug' => $slug,
            ]);
            $payload['media'][0]['url'] = $url;

            $this->actingAs($staff)
                ->from(route('admin.tours.create'))
                ->post(route('admin.tours.store'), $payload)
                ->assertRedirect(route('admin.tours.create'))
                ->assertSessionHasErrors('media.0.url');
            $this->assertDatabaseMissing('tour_packages', ['slug' => $slug]);
        }
    }

    public function test_departure_nested_under_another_package_is_404(): void
    {
        $staff = $this->operationsUser();
        $firstPackage = $this->publishedTour();
        $otherPackage = $this->publishedTour();
        $departure = $this->bookableDeparture($firstPackage);

        $this->actingAs($staff)
            ->patch(route('admin.tour-departures.update', [$otherPackage, $departure]), [
                'starts_at' => $departure->starts_at->format('Y-m-d H:i:s'),
                'ends_at' => $departure->ends_at->format('Y-m-d H:i:s'),
                'cancellation_cutoff_at' => $departure->cancellation_cutoff_at->format('Y-m-d H:i:s'),
                'capacity' => $departure->capacity,
                'currency' => 'UGX',
            ])
            ->assertNotFound();
    }

    public function test_staff_can_create_and_render_an_itinerary_longer_than_five_days(): void
    {
        $staff = $this->operationsUser();
        $category = TourCategory::factory()->create(['is_active' => true]);
        $itinerary = [];

        foreach (range(1, 7) as $day) {
            $itinerary[] = [
                'day_number' => $day,
                'title' => 'Journey day '.$day,
                'description' => 'Customer-facing plan for journey day '.$day.'.',
                'activities' => $day === 1
                    ? "Game drive, photography and birding\nBoat safari"
                    : "Morning activity\nAfternoon activity",
                'meals' => 'Breakfast and dinner',
                'overnight_location' => 'Tour lodge '.$day,
            ];
        }

        $this->actingAs($staff)
            ->post(route('admin.tours.store'), [
                'category_id' => $category->getKey(),
                'name' => 'Seven Day Uganda Circuit',
                'slug' => 'seven-day-uganda-circuit',
                'destination' => 'Western Uganda',
                'summary' => 'A complete seven-day circuit prepared for the package editor test.',
                'description' => 'A detailed seven-day itinerary covering wildlife, landscapes, and community visits.',
                'duration_days' => 7,
                'base_price' => '2500000',
                'currency' => 'UGX',
                'min_travelers' => 1,
                'max_travelers' => 12,
                'cancellation_cutoff_hours' => 48,
                'is_featured' => '0',
                'media' => [[
                    'url' => 'https://example.test/seven-day-tour.jpg',
                    'alt_text' => 'Travelers overlooking a Ugandan landscape',
                    'caption' => 'Seven-day Uganda circuit',
                    'is_cover' => '1',
                ]],
                'itinerary' => $itinerary,
                'inclusions' => ['Professional guide'],
                'exclusions' => ['International flights'],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $package = TourPackage::query()->where('slug', 'seven-day-uganda-circuit')->firstOrFail();
        $this->assertSame(7, $package->duration_days);
        $this->assertCount(7, $package->itineraryDays);
        $this->assertSame(
            ['Game drive, photography and birding', 'Boat safari'],
            $package->itineraryDays()->where('day_number', 1)->sole()->activities,
        );

        // The itinerary is no longer edited through this form — it was removed
        // to keep package creation to three steps. The data is still stored and
        // still rendered publicly, and SaveTourPackage only touches it when the
        // key is present, so what was captured here survives an edit.
        $this->actingAs($staff)
            ->get(route('admin.tours.edit', $package))
            ->assertOk()
            ->assertDontSee('name="itinerary[6][day_number]"', false);

        $this->actingAs($staff)
            ->patch(route('admin.tours.update', $package), [
                'tour_category_id' => $category->id,
                'name' => $package->name,
                'destination' => $package->destination,
                'summary' => $package->summary,
                'description' => $package->description,
                'duration_days' => $package->duration_days,
                'base_price' => '1200000',
                'currency' => 'UGX',
                'min_travelers' => 1,
                'max_travelers' => 8,
            ])
            ->assertRedirect();

        $this->assertSame(
            7,
            $package->fresh()->itineraryDays()->count(),
            'Editing a package without an itinerary field discarded the itinerary.',
        );
    }

    /** @return array<string, mixed> */
    private function newPackageFormPayload(TourCategory $category, array $overrides = []): array
    {
        return array_replace([
            'category_id' => $category->getKey(),
            'name' => 'New HTTP Tour',
            'slug' => 'new-http-tour',
            'destination' => 'Western Uganda',
            'summary' => 'A complete tour package submitted through the administration form.',
            'description' => 'A detailed customer-facing description for this tour package.',
            'duration_days' => 1,
            'base_price' => '250000',
            'currency' => 'UGX',
            'min_travelers' => 1,
            'max_travelers' => 12,
            'cancellation_cutoff_hours' => 48,
            'is_featured' => false,
            'media' => [[
                'url' => '/storage/tours/new-http-tour.jpg',
                'alt_text' => 'Tour landscape',
                'caption' => 'Tour cover',
                'is_cover' => true,
            ]],
            'itinerary' => [[
                'day_number' => 1,
                'title' => 'Arrival and tour',
                'description' => 'Meet the guide and begin the planned activities.',
                'activities' => 'Orientation',
                'meals' => null,
                'overnight_location' => null,
            ]],
            'inclusions' => ['Professional guide'],
            'exclusions' => ['Personal expenses'],
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function packageUpdatePayload(TourPackage $package, array $overrides = []): array
    {
        $package->load(['media', 'itineraryDays', 'inclusions', 'exclusions']);

        return array_merge([
            'category_id' => $package->tour_category_id,
            'name' => $package->name,
            'slug' => $package->slug,
            'destination' => $package->destination,
            'summary' => $package->summary,
            'description' => $package->description,
            'duration_days' => $package->duration_days,
            'base_price' => Money::forInput((int) $package->base_price_minor, $package->currency),
            'currency' => $package->currency,
            'min_travelers' => $package->min_travelers,
            'max_travelers' => $package->max_travelers,
            'cancellation_cutoff_hours' => $package->cancellation_cutoff_hours,
            'is_featured' => $package->is_featured,
            'media' => $package->media->map(fn ($medium): array => [
                'url' => $medium->url,
                'alt_text' => $medium->alt_text,
                'caption' => $medium->caption,
                'is_cover' => $medium->is_cover,
            ])->all(),
            'itinerary' => $package->itineraryDays->map(fn ($day): array => [
                'day_number' => $day->day_number,
                'title' => $day->title,
                'description' => $day->description,
                'activities' => null,
                'meals' => $day->meals,
                'overnight_location' => $day->overnight_location,
            ])->all(),
            'inclusions' => $package->inclusions->pluck('content')->all(),
            'exclusions' => $package->exclusions->pluck('content')->all(),
        ], $overrides);
    }

    private function completeDraftPackage(): TourPackage
    {
        $category = TourCategory::factory()->create(['is_active' => true]);
        $package = TourPackage::factory()->for($category, 'category')->create([
            'status' => TourPackageStatus::Draft,
            'published_at' => null,
            'duration_days' => 2,
        ]);
        $package->media()->create([
            'url' => 'https://example.test/storage/tours/complete-cover.jpg',
            'alt_text' => 'Complete tour cover',
            'is_cover' => true,
            'sort_order' => 0,
        ]);
        $package->itineraryDays()->createMany([
            ['day_number' => 1, 'title' => 'Day one', 'description' => 'Arrival and orientation.', 'sort_order' => 0],
            ['day_number' => 2, 'title' => 'Day two', 'description' => 'Guided tour activities.', 'sort_order' => 1],
        ]);
        $package->items()->createMany([
            [
                'item_type' => TourPackageItemType::Inclusion,
                'content' => 'Professional guide',
                'sort_order' => 0,
            ],
            [
                'item_type' => TourPackageItemType::Exclusion,
                'content' => 'Personal expenses',
                'sort_order' => 0,
            ],
        ]);

        return $package->fresh(['category', 'media', 'itineraryDays', 'items']);
    }
}
