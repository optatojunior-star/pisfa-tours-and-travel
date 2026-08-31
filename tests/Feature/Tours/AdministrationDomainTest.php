<?php

namespace Tests\Feature\Tours;

use App\Actions\Tours\SaveTourDeparture;
use App\Actions\Tours\SaveTourPackage;
use App\Enums\TourBookingStatus;
use App\Enums\TourDepartureStatus;
use App\Enums\TourPackageStatus;
use App\Enums\UserRole;
use App\Models\TourCategory;
use App\Models\TourDeparture;
use App\Models\TourPackage;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Tours\Concerns\BuildsTourFixtures;
use Tests\TestCase;

class AdministrationDomainTest extends TestCase
{
    use BuildsTourFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-08-20 09:00:00');
    }

    /** @return array<string, array{string, string}> */
    public static function missingPublishPrerequisites(): array
    {
        return [
            'inactive category' => ['inactive-category', 'tour_category_id'],
            'no cover image' => ['no-cover', 'media'],
            'more than one cover image' => ['two-covers', 'media'],
            'missing itinerary day' => ['missing-day', 'itinerary_days'],
            'missing inclusions' => ['no-inclusions', 'inclusions'],
            'missing exclusions' => ['no-exclusions', 'inclusions'],
        ];
    }

    #[DataProvider('missingPublishPrerequisites')]
    public function test_package_publication_requires_complete_customer_facing_content(
        string $case,
        string $errorKey,
    ): void {
        $staff = $this->operationsUser();
        $category = TourCategory::factory()->create(['is_active' => $case !== 'inactive-category']);
        $payload = $this->publishablePackagePayload($category);

        match ($case) {
            'no-cover' => $payload['media'][0]['is_cover'] = false,
            'two-covers' => $payload['media'][] = [
                'url' => '/storage/tours/second-cover.jpg',
                'alt_text' => 'A second cover',
                'is_cover' => true,
            ],
            'missing-day' => array_pop($payload['itinerary_days']),
            'no-inclusions' => $payload['inclusions'] = [],
            'no-exclusions' => $payload['exclusions'] = [],
            default => null,
        };

        try {
            app(SaveTourPackage::class)->execute($staff, $payload);
            $this->fail("The {$case} package was published.");
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($errorKey, $exception->errors());
            $this->assertDatabaseCount('tour_packages', 0);
            $this->assertDatabaseCount('tour_package_media', 0);
            $this->assertDatabaseCount('tour_itinerary_days', 0);
            $this->assertDatabaseCount('tour_package_items', 0);
            $this->assertDatabaseCount('audit_logs', 0);
        }
    }

    public function test_complete_package_is_published_with_exact_money_nested_content_and_audit(): void
    {
        $staff = $this->operationsUser(UserRole::Manager);
        $category = TourCategory::factory()->create(['is_active' => true]);

        $package = app(SaveTourPackage::class)->execute(
            $staff,
            $this->publishablePackagePayload($category),
        );

        $this->assertSame(TourPackageStatus::Published, $package->status);
        $this->assertNotNull($package->published_at);
        $this->assertSame(19_995, $package->base_price_minor);
        $this->assertSame('USD', $package->currency);
        $this->assertSame($staff->getKey(), $package->created_by_user_id);
        $this->assertSame($staff->getKey(), $package->updated_by_user_id);
        $this->assertCount(1, $package->media);
        $this->assertTrue($package->media->sole()->is_cover);
        $this->assertCount(2, $package->itineraryDays);
        $this->assertSame(['Game drive', 'Boat trip'], $package->itineraryDays->first()->activities);
        $this->assertSame(1, $package->inclusions()->count());
        $this->assertSame(1, $package->exclusions()->count());
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'tour_package.created',
            'auditable_id' => $package->getKey(),
            'user_id' => $staff->getKey(),
        ]);
    }

    public function test_failed_publication_update_rolls_back_package_and_nested_replacements(): void
    {
        $staff = $this->operationsUser();
        $category = TourCategory::factory()->create(['is_active' => true]);
        $package = TourPackage::factory()->for($category, 'category')->create([
            'name' => 'Original Draft',
            'status' => TourPackageStatus::Draft,
        ]);
        $package->media()->create([
            'url' => '/storage/tours/original.jpg',
            'alt_text' => 'Original image',
            'is_cover' => true,
            'sort_order' => 0,
        ]);
        $package->itineraryDays()->create([
            'day_number' => 1,
            'title' => 'Original day',
            'sort_order' => 0,
        ]);

        $payload = $this->publishablePackagePayload($category);
        $payload['name'] = 'Replacement Name';
        $payload['exclusions'] = [];

        try {
            app(SaveTourPackage::class)->execute($staff, $payload, $package);
            $this->fail('An incomplete publication update succeeded.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('inclusions', $exception->errors());
        }

        $package->refresh();
        $this->assertSame('Original Draft', $package->name);
        $this->assertSame(TourPackageStatus::Draft, $package->status);
        $this->assertDatabaseHas('tour_package_media', [
            'tour_package_id' => $package->getKey(),
            'url' => '/storage/tours/original.jpg',
        ]);
        $this->assertDatabaseHas('tour_itinerary_days', [
            'tour_package_id' => $package->getKey(),
            'title' => 'Original day',
        ]);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_archived_package_cannot_transition_directly_to_published(): void
    {
        $staff = $this->operationsUser();
        $category = TourCategory::factory()->create(['is_active' => true]);
        $package = TourPackage::factory()->archived()->for($category, 'category')->create();

        try {
            app(SaveTourPackage::class)->execute(
                $staff,
                $this->publishablePackagePayload($category),
                $package,
            );
            $this->fail('An archived package was directly published.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
            $this->assertSame(TourPackageStatus::Archived, $package->fresh()->status);
        }
    }

    public function test_package_and_departure_actions_enforce_the_canonical_configured_caps(): void
    {
        $this->assertSame(90, config('tours.maximum_duration_days'));
        $this->assertSame(50, config('tours.maximum_booking_travelers'));
        $this->assertSame(500, config('tours.maximum_departure_capacity'));

        $staff = $this->operationsUser();
        $category = TourCategory::factory()->create(['is_active' => true]);
        $payload = array_replace($this->publishablePackagePayload($category), [
            'name' => 'Canonical Boundary Tour',
            'slug' => 'canonical-boundary-tour',
            'status' => TourPackageStatus::Draft->value,
            'duration_days' => 90,
            'min_travelers' => 1,
            'max_travelers' => 50,
        ]);

        $package = app(SaveTourPackage::class)->execute($staff, $payload);
        $this->assertSame(90, $package->duration_days);
        $this->assertSame(50, $package->max_travelers);

        foreach ([
            'duration_days' => ['duration_days' => 91],
            'minimum travelers' => ['min_travelers' => 51, 'max_travelers' => 51],
            'maximum travelers' => ['max_travelers' => 51],
        ] as $case => $invalid) {
            $invalidPayload = array_replace($payload, $invalid, [
                'name' => 'Invalid '.$case,
                'slug' => 'invalid-'.str($case)->slug(),
            ]);

            try {
                app(SaveTourPackage::class)->execute($staff, $invalidPayload);
                $this->fail("The package action accepted {$case} above its canonical cap.");
            } catch (ValidationException $exception) {
                $this->assertNotEmpty(array_intersect(
                    ['duration_days', 'min_travelers', 'max_travelers'],
                    array_keys($exception->errors()),
                ));
            }
        }

        $startsAt = now()->toImmutable()->addDays(20)->startOfHour();
        $departurePayload = [
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addDay(),
            'cancellation_cutoff_at' => $startsAt->subDay(),
            'capacity' => 500,
            'status' => TourDepartureStatus::Scheduled->value,
        ];
        $departure = app(SaveTourDeparture::class)->execute($staff, $package, $departurePayload);
        $this->assertSame(500, $departure->capacity);

        try {
            app(SaveTourDeparture::class)->execute($staff, $package, array_replace(
                $departurePayload,
                [
                    'starts_at' => $startsAt->addDays(2),
                    'ends_at' => $startsAt->addDays(3),
                    'cancellation_cutoff_at' => $startsAt->addDay(),
                    'capacity' => 501,
                ],
            ));
            $this->fail('The departure action accepted capacity above 500.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('capacity', $exception->errors());
        }
    }

    public function test_package_action_uses_the_shared_public_media_url_contract(): void
    {
        $staff = $this->operationsUser();
        $category = TourCategory::factory()->create(['is_active' => true]);

        foreach ([
            'https' => 'https://cdn.example.test/tours/cover.jpg',
            'storage path' => '/storage/tours/cover.jpg',
        ] as $case => $url) {
            $payload = $this->publishablePackagePayload($category);
            $payload['name'] = 'Accepted '.$case;
            $payload['slug'] = 'accepted-'.str($case)->slug();
            $payload['media'][0]['url'] = $url;

            $package = app(SaveTourPackage::class)->execute($staff, $payload);
            $this->assertSame($url, $package->media->sole()->url);
        }

        foreach ([
            'http' => 'http://cdn.example.test/tours/cover.jpg',
            'protocol relative' => '//cdn.example.test/tours/cover.jpg',
            'backslash' => '\\storage\\tours\\cover.jpg',
            'control character' => "/storage/tours/cover\nname.jpg",
        ] as $case => $url) {
            $payload = $this->publishablePackagePayload($category);
            $payload['name'] = 'Rejected '.$case;
            $payload['slug'] = 'rejected-'.str($case)->slug();
            $payload['media'][0]['url'] = $url;

            try {
                app(SaveTourPackage::class)->execute($staff, $payload);
                $this->fail("The package action accepted the unsafe {$case} media URL.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('media.0.url', $exception->errors());
                $this->assertDatabaseMissing('tour_packages', ['slug' => $payload['slug']]);
            }
        }
    }

    public function test_departure_creation_uses_exact_override_money_utc_dates_and_audit(): void
    {
        $staff = $this->operationsUser();
        $package = $this->publishedTour(['cancellation_cutoff_hours' => 72]);
        $startsAt = now()->toImmutable()->addDays(8)->startOfHour();

        $departure = app(SaveTourDeparture::class)->execute($staff, $package, [
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addDays(2),
            'cancellation_cutoff_at' => null,
            'capacity' => 18,
            'price_override' => '425.75',
            'currency' => 'usd',
            'status' => TourDepartureStatus::Scheduled->value,
            'meeting_point' => 'PISFA Kampala office',
        ]);

        $this->assertSame(42_575, $departure->price_override_minor);
        $this->assertSame('USD', $departure->currency);
        $this->assertTrue($departure->starts_at->equalTo($startsAt));
        $this->assertTrue($departure->cancellation_cutoff_at->equalTo($startsAt->subHours(72)));
        $this->assertSame(18, $departure->capacity);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'tour_departure.created',
            'auditable_id' => $departure->getKey(),
            'user_id' => $staff->getKey(),
        ]);
    }

    public function test_departure_creation_accepts_business_dates_beyond_the_2038_timestamp_limit(): void
    {
        $staff = $this->operationsUser();
        $package = $this->publishedTour();

        $departure = app(SaveTourDeparture::class)->execute($staff, $package, [
            'starts_at' => '2040-01-15 08:00:00',
            'ends_at' => '2040-01-17 18:00:00',
            'cancellation_cutoff_at' => '2040-01-13 08:00:00',
            'capacity' => 12,
            'status' => TourDepartureStatus::Scheduled->value,
        ]);

        $this->assertSame('2040-01-15 05:00:00', $departure->starts_at->format('Y-m-d H:i:s'));
        $this->assertSame('2040-01-17 15:00:00', $departure->ends_at->format('Y-m-d H:i:s'));
        $this->assertDatabaseHas('tour_departures', [
            'id' => $departure->getKey(),
            'starts_at' => '2040-01-15 05:00:00',
        ]);
    }

    public function test_capacity_cannot_be_reduced_below_reserved_seats(): void
    {
        $staff = $this->operationsUser();
        $departure = $this->bookableDeparture(attributes: ['capacity' => 8]);
        $this->persistedBooking(
            $this->customer(),
            $departure,
            TourBookingStatus::Confirmed,
            5,
        );

        try {
            app(SaveTourDeparture::class)->execute(
                $staff,
                $departure->tourPackage,
                $this->departurePayload($departure, ['capacity' => 4]),
                $departure,
            );
            $this->fail('Capacity was reduced below reserved seats.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('capacity', $exception->errors());
            $this->assertSame(8, $departure->fresh()->capacity);
        }
    }

    public function test_dates_and_cutoff_cannot_change_after_any_booking_exists(): void
    {
        $staff = $this->operationsUser();
        $departure = $this->bookableDeparture();
        $this->persistedBooking(
            $this->customer(),
            $departure,
            TourBookingStatus::Cancelled,
        );

        try {
            app(SaveTourDeparture::class)->execute(
                $staff,
                $departure->tourPackage,
                $this->departurePayload($departure, [
                    'starts_at' => $departure->starts_at->addHour(),
                    'ends_at' => $departure->ends_at->addHour(),
                ]),
                $departure,
            );
            $this->fail('Booked departure dates were changed.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('starts_at', $exception->errors());
            $this->assertTrue($departure->fresh()->starts_at->equalTo($departure->starts_at));
        }
    }

    public function test_capacity_holding_bookings_block_permanent_departure_statuses(): void
    {
        $staff = $this->operationsUser();
        $departure = $this->bookableDeparture();
        $this->persistedBooking($this->customer(), $departure, TourBookingStatus::Pending, 2);

        foreach ([TourDepartureStatus::Cancelled, TourDepartureStatus::Completed] as $status) {
            try {
                app(SaveTourDeparture::class)->execute(
                    $staff,
                    $departure->tourPackage,
                    $this->departurePayload($departure, ['status' => $status->value]),
                    $departure,
                );
                $this->fail("A departure with held seats became {$status->value}.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('status', $exception->errors());
                $this->assertSame(TourDepartureStatus::Scheduled, $departure->fresh()->status);
            }
        }
    }

    public function test_archived_packages_reject_new_departures(): void
    {
        $staff = $this->operationsUser();
        $package = TourPackage::factory()->archived()->create();
        $startsAt = now()->toImmutable()->addDays(5);

        try {
            app(SaveTourDeparture::class)->execute($staff, $package, [
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->addDay(),
                'capacity' => 10,
                'status' => TourDepartureStatus::Scheduled->value,
            ]);
            $this->fail('A departure was added to an archived package.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('tour_package_id', $exception->errors());
            $this->assertDatabaseCount('tour_departures', 0);
        }
    }

    public function test_duplicate_utc_start_is_rejected_within_package_but_allowed_across_packages(): void
    {
        $staff = $this->operationsUser();
        $firstPackage = $this->publishedTour();
        $secondPackage = $this->publishedTour();
        $startsAt = now()->toImmutable()->addDays(9)->startOfHour();
        $existing = $this->bookableDeparture($firstPackage, [
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addDay(),
            'cancellation_cutoff_at' => $startsAt->subDay(),
        ]);
        $payload = [
            'starts_at' => $startsAt->setTimezone('Africa/Kampala'),
            'ends_at' => $startsAt->addDays(2),
            'cancellation_cutoff_at' => $startsAt->subHours(12),
            'capacity' => 10,
            'status' => TourDepartureStatus::Scheduled->value,
        ];

        try {
            app(SaveTourDeparture::class)->execute($staff, $firstPackage, $payload);
            $this->fail('The same package accepted a duplicate UTC departure start.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('starts_at', $exception->errors());
            $this->assertDatabaseCount('tour_departures', 1);
        }

        $otherPackageDeparture = app(SaveTourDeparture::class)->execute($staff, $secondPackage, $payload);

        $this->assertFalse($existing->is($otherPackageDeparture));
        $this->assertTrue($existing->starts_at->equalTo($otherPackageDeparture->starts_at));
        $this->assertDatabaseCount('tour_departures', 2);
    }

    /** @return array<string, array{UserRole}> */
    public static function unauthorizedAdministrationRoles(): array
    {
        return [
            'customer' => [UserRole::Customer],
            'driver' => [UserRole::Driver],
        ];
    }

    #[DataProvider('unauthorizedAdministrationRoles')]
    public function test_customer_and_driver_roles_cannot_mutate_packages_or_departures(UserRole $role): void
    {
        $actor = $this->user($role);
        $category = TourCategory::factory()->create();

        try {
            app(SaveTourPackage::class)->execute($actor, $this->publishablePackagePayload($category));
            $this->fail('A non-operations role saved a package.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('tour_packages', 0);
        }
    }

    /** @return array<string, mixed> */
    private function publishablePackagePayload(TourCategory $category): array
    {
        return [
            'tour_category_id' => $category->getKey(),
            'name' => 'Murchison Falls Discovery',
            'slug' => 'murchison-falls-discovery',
            'destination' => 'Masindi',
            'summary' => 'A complete two-day wildlife and river experience.',
            'description' => 'A detailed itinerary prepared and operated by PISFA.',
            'status' => TourPackageStatus::Published->value,
            'is_featured' => true,
            'duration_days' => 2,
            'base_price' => '199.95',
            'currency' => 'USD',
            'min_travelers' => 1,
            'max_travelers' => 12,
            'cancellation_cutoff_hours' => 48,
            'media' => [[
                'url' => '/storage/tours/murchison-cover.jpg',
                'alt_text' => 'Murchison Falls at sunset',
                'caption' => 'The Nile at Murchison Falls',
                'is_cover' => true,
                'sort_order' => 0,
            ]],
            'itinerary_days' => [
                [
                    'day_number' => 1,
                    'title' => 'Wildlife and river',
                    'description' => 'Morning and afternoon activities.',
                    'activities' => ['Game drive', 'Boat trip'],
                    'meals' => 'Lunch and dinner',
                    'overnight_location' => 'Murchison Lodge',
                    'sort_order' => 0,
                ],
                [
                    'day_number' => 2,
                    'title' => 'Falls and return',
                    'description' => 'Visit the falls before returning.',
                    'activities' => ['Top of the falls'],
                    'meals' => 'Breakfast',
                    'overnight_location' => null,
                    'sort_order' => 1,
                ],
            ],
            'inclusions' => [['content' => 'Professional guide', 'sort_order' => 0]],
            'exclusions' => [['content' => 'Personal expenses', 'sort_order' => 0]],
        ];
    }

    /** @return array<string, mixed> */
    private function departurePayload(TourDeparture $departure, array $overrides = []): array
    {
        return array_merge([
            'starts_at' => $departure->starts_at,
            'ends_at' => $departure->ends_at,
            'cancellation_cutoff_at' => $departure->cancellation_cutoff_at,
            'capacity' => $departure->capacity,
            'price_override_minor' => $departure->price_override_minor,
            'currency' => $departure->currency,
            'status' => $departure->status->value,
            'meeting_point' => $departure->meeting_point,
            'customer_notes' => $departure->customer_notes,
            'internal_notes' => $departure->internal_notes,
        ], $overrides);
    }
}
