<?php

namespace Tests\Feature\Tours;

use App\Enums\AccountStatus;
use App\Enums\TourBookingStatus;
use App\Enums\UserRole;
use App\Models\TourBooking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Tours\Concerns\BuildsTourFixtures;
use Tests\TestCase;

class TourRouteAuthorizationTest extends TestCase
{
    use BuildsTourFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo('2026-08-20 09:00:00');
    }

    public function test_guests_are_sent_to_login_for_customer_and_administration_routes(): void
    {
        $package = $this->publishedTour();
        $departure = $this->bookableDeparture($package);

        foreach ([
            route('tour-bookings.create', [$package, 'departure' => $departure->getKey()]),
            route('portal.bookings.index'),
            route('admin.tours.index'),
            route('admin.tour-categories.index'),
            route('admin.tour-bookings.index'),
        ] as $url) {
            $this->get($url)->assertRedirect(route('login'));
        }

        $this->patch(route('admin.tours.restore', $package))
            ->assertRedirect(route('login'));
    }

    public function test_unverified_customer_is_sent_to_email_verification_before_booking_or_portal_access(): void
    {
        $customer = $this->customer(['email_verified_at' => null]);
        $package = $this->publishedTour();
        $departure = $this->bookableDeparture($package);

        $this->actingAs($customer)
            ->get(route('tour-bookings.create', [$package, 'departure' => $departure->getKey()]))
            ->assertRedirect(route('verification.notice'));
        $this->actingAs($customer)
            ->get(route('portal.bookings.index'))
            ->assertRedirect(route('verification.notice'));
    }

    /** @return array<string, array{UserRole}> */
    public static function nonCustomerRoles(): array
    {
        return [
            'staff' => [UserRole::Staff],
            'manager' => [UserRole::Manager],
            'driver' => [UserRole::Driver],
            'super administrator' => [UserRole::SuperAdmin],
        ];
    }

    #[DataProvider('nonCustomerRoles')]
    public function test_non_customer_roles_are_denied_booking_and_customer_portal_routes(UserRole $role): void
    {
        $actor = $this->user($role, ['two_factor_required' => false]);
        $package = $this->publishedTour();
        $departure = $this->bookableDeparture($package);

        $this->actingAs($actor)
            ->get(route('tour-bookings.create', [$package, 'departure' => $departure->getKey()]))
            ->assertForbidden();
        $this->actingAs($actor)
            ->get(route('portal.bookings.index'))
            ->assertForbidden();
    }

    /** @return array<string, array{UserRole}> */
    public static function nonAdministrationRoles(): array
    {
        return [
            'customer' => [UserRole::Customer],
            'driver' => [UserRole::Driver],
        ];
    }

    #[DataProvider('nonAdministrationRoles')]
    public function test_customer_and_driver_roles_are_denied_every_administration_area(UserRole $role): void
    {
        $actor = $this->user($role);
        $package = $this->publishedTour();

        foreach ([
            route('admin.tours.index'),
            route('admin.tour-categories.index'),
            route('admin.tour-bookings.index'),
        ] as $url) {
            $this->actingAs($actor)->get($url)->assertForbidden();
        }

        $this->actingAs($actor)
            ->patch(route('admin.tours.restore', $package))
            ->assertForbidden();
    }

    public function test_inactive_accounts_are_denied_before_tour_authorization(): void
    {
        $customer = $this->customer(['status' => AccountStatus::Inactive]);

        $this->actingAs($customer)
            ->get(route('portal.bookings.index'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_customer_can_only_resolve_their_own_booking_and_foreign_reference_is_404(): void
    {
        $owner = $this->customer();
        $otherCustomer = $this->customer();
        $departure = $this->bookableDeparture();
        $owned = $this->persistedBooking($owner, $departure);
        $foreign = $this->persistedBooking($otherCustomer, $departure);

        $this->actingAs($owner)
            ->get(route('portal.bookings.show', $owned))
            ->assertOk()
            ->assertSee($owned->reference);
        $this->actingAs($owner)
            ->get(route('portal.bookings.show', $foreign))
            ->assertNotFound();
        $this->actingAs($owner)
            ->patch(route('portal.bookings.cancel', $foreign), [
                'reason' => 'This should not be accepted.',
                'confirm_cancellation' => '1',
            ])
            ->assertNotFound();

        $this->assertSame(TourBookingStatus::Pending, $foreign->fresh()->status);
    }

    public function test_http_booking_submission_discards_server_owned_fields_and_uses_authenticated_customer(): void
    {
        $customer = $this->customer([
            'name' => 'Amina Customer',
            'email' => 'amina@example.test',
        ]);
        $attacker = $this->customer();
        $package = $this->publishedTour([
            'name' => 'Secure HTTP Safari',
            'base_price_minor' => 350_000,
            'currency' => 'UGX',
        ]);
        $departure = $this->bookableDeparture($package, [
            'price_override_minor' => 400_000,
            'currency' => 'UGX',
        ]);
        $idempotencyKey = (string) Str::uuid();

        $response = $this->actingAs($customer)->post(route('tour-bookings.store', $package), [
            'departure_id' => $departure->getKey(),
            'idempotency_key' => $idempotencyKey,
            'contact_phone' => '+256701234567',
            'special_requests' => 'Vegetarian meals.',
            'accept_terms' => '1',
            'travelers' => [[
                'full_name' => 'Amina Customer',
                'traveler_type' => 'adult',
                'date_of_birth' => '1990-04-12',
                'nationality' => 'Ugandan',
            ]],
            'reference' => 'FORGED-REFERENCE',
            'customer_id' => $attacker->getKey(),
            'status' => TourBookingStatus::Completed->value,
            'currency' => 'USD',
            'unit_price_minor' => 1,
            'total_minor' => 1,
            'package_name_snapshot' => 'Forged package',
        ]);

        $booking = TourBooking::query()->sole();
        $response
            ->assertRedirect(route('portal.bookings.show', $booking))
            ->assertSessionHasNoErrors();
        $this->assertSame($customer->getKey(), $booking->customer_id);
        $this->assertSame(TourBookingStatus::Pending, $booking->status);
        $this->assertSame('Secure HTTP Safari', $booking->package_name_snapshot);
        $this->assertSame(400_000, $booking->unit_price_minor);
        $this->assertSame(400_000, $booking->total_minor);
        $this->assertSame('UGX', $booking->currency);
        $this->assertSame('Amina Customer', $booking->contact_name);
        $this->assertSame('amina@example.test', $booking->contact_email);
        $this->assertSame('+256701234567', $booking->contact_phone);
        $this->assertNotSame('FORGED-REFERENCE', $booking->reference);
    }

    public function test_http_booking_accepts_fifty_travelers_and_date_of_birth_today_but_rejects_fifty_one(): void
    {
        $maximum = (int) config('tours.maximum_booking_travelers');
        $this->assertSame(50, $maximum);
        $customer = $this->customer();
        $package = $this->publishedTour([
            'min_travelers' => 1,
            'max_travelers' => $maximum,
        ]);
        $departure = $this->bookableDeparture($package, ['capacity' => 500]);
        $today = now(config('pisfa.business_timezone'))->toDateString();
        $travelers = [];

        foreach (range(1, $maximum) as $index) {
            $travelers[] = [
                'full_name' => 'HTTP Traveler '.$index,
                'traveler_type' => 'adult',
                'date_of_birth' => $index === 1 ? $today : '1990-04-12',
                'nationality' => 'Ugandan',
            ];
        }

        $response = $this->actingAs($customer)->post(route('tour-bookings.store', $package), [
            'departure_id' => $departure->getKey(),
            'idempotency_key' => (string) Str::uuid(),
            'contact_phone' => '+256701234567',
            'accept_terms' => '1',
            'travelers' => $travelers,
        ]);

        $booking = TourBooking::query()->sole();
        $response
            ->assertRedirect(route('portal.bookings.show', $booking))
            ->assertSessionHasNoErrors();
        $this->assertSame(50, $booking->traveler_count);
        $this->assertCount(50, $booking->travelers);
        $this->assertSame($today, $booking->travelers->first()->date_of_birth?->toDateString());

        $travelers[] = [
            'full_name' => 'HTTP Traveler 51',
            'traveler_type' => 'adult',
            'date_of_birth' => '1990-04-12',
            'nationality' => 'Ugandan',
        ];

        $this->actingAs($customer)
            ->from(route('tour-bookings.create', [$package, 'departure' => $departure->getKey()]))
            ->post(route('tour-bookings.store', $package), [
                'departure_id' => $departure->getKey(),
                'idempotency_key' => (string) Str::uuid(),
                'contact_phone' => '+256701234567',
                'accept_terms' => '1',
                'travelers' => $travelers,
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('travelers');

        $this->assertDatabaseCount('tour_bookings', 1);
    }

    public function test_public_booking_and_portal_pages_render_mobile_viewport_and_responsive_layouts(): void
    {
        $customer = $this->customer();
        $package = $this->publishedTour(['name' => 'Responsive Safari']);
        $departure = $this->bookableDeparture($package);
        $booking = $this->persistedBooking($customer, $departure);

        foreach ([route('tours.index'), route('tours.show', $package)] as $url) {
            $this->get($url)
                ->assertOk()
                ->assertSee('name="viewport"', false)
                ->assertSee('sm:', false);
        }

        foreach ([
            route('tour-bookings.create', [$package, 'departure' => $departure->getKey()]),
            route('portal.bookings.index'),
            route('portal.bookings.show', $booking),
        ] as $url) {
            $this->actingAs($customer)
                ->get($url)
                ->assertOk()
                ->assertSee('name="viewport"', false)
                ->assertSee('sm:', false);
        }
    }
}
