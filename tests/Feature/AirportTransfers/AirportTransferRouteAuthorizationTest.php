<?php

namespace Tests\Feature\AirportTransfers;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\AirportTransfers\Concerns\BuildsAirportTransferFixtures;
use Tests\TestCase;

class AirportTransferRouteAuthorizationTest extends TestCase
{
    use BuildsAirportTransferFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo('2026-08-20 09:00:00');
    }

    public function test_the_public_planner_stays_open_while_portal_and_administration_require_sign_in(): void
    {
        $this->get(route('airport-transfers.index'))->assertOk();

        foreach ([
            route('portal.airport-transfer-bookings.index'),
            route('admin.airport-transfer-bookings.index'),
            route('admin.airport-transfer-settings.index'),
        ] as $url) {
            $this->get($url)->assertRedirect(route('login'));
        }
    }

    public function test_an_unverified_customer_is_sent_to_email_verification_before_the_portal(): void
    {
        $unverified = $this->customer(['email_verified_at' => null]);

        $this->actingAs($unverified)
            ->get(route('portal.airport-transfer-bookings.index'))
            ->assertRedirect(route('verification.notice'));
    }

    public function test_non_customer_roles_are_denied_the_customer_portal(): void
    {
        foreach ([UserRole::Staff, UserRole::Manager, UserRole::Driver, UserRole::SuperAdmin] as $role) {
            $actor = $this->user($role, ['two_factor_required' => false]);

            $this->actingAs($actor)
                ->get(route('portal.airport-transfer-bookings.index'))
                ->assertForbidden();
        }
    }

    public function test_customers_and_drivers_are_denied_every_administration_screen(): void
    {
        foreach ([$this->customer(), $this->driver()] as $actor) {
            $this->actingAs($actor)->get(route('admin.airport-transfer-bookings.index'))->assertForbidden();
            $this->actingAs($actor)->get(route('admin.airport-transfer-settings.index'))->assertForbidden();
        }
    }

    public function test_operations_staff_reach_the_administration_screens(): void
    {
        $staff = $this->operationsUser();

        $this->actingAs($staff)
            ->get(route('admin.airport-transfer-bookings.index'))
            ->assertOk()
            ->assertSee('Airport transfers');
        $this->actingAs($staff)
            ->get(route('admin.airport-transfer-settings.index'))
            ->assertOk()
            ->assertSee('Transfer rates');
    }

    public function test_roles_under_the_mandatory_two_factor_policy_must_configure_it_first(): void
    {
        // config('security.two_factor.required_roles') covers manager and
        // super_admin, so the transfer console is gated behind 2FA setup.
        foreach ([UserRole::Manager, UserRole::SuperAdmin] as $role) {
            $actor = $this->user($role, ['two_factor_required' => false]);

            $this->actingAs($actor)
                ->get(route('admin.airport-transfer-bookings.index'))
                ->assertRedirect(route('profile.security'));
        }
    }

    public function test_an_inactive_account_is_stopped_before_transfer_authorization(): void
    {
        $inactive = $this->customer(['status' => AccountStatus::Suspended]);

        $this->actingAs($inactive)
            ->get(route('portal.airport-transfer-bookings.index'))
            ->assertRedirect(route('login'));
    }

    public function test_a_customer_can_only_resolve_their_own_transfer_and_a_foreign_reference_is_not_found(): void
    {
        [$airport, $location] = $this->bookableRoute();
        $owner = $this->customer();
        $other = $this->customer();
        $booking = $this->createBooking($owner, $airport, $location);

        $this->actingAs($owner)
            ->get(route('portal.airport-transfer-bookings.show', $booking))
            ->assertOk()
            ->assertSee($booking->reference);

        $this->actingAs($other)
            ->get(route('portal.airport-transfer-bookings.show', $booking))
            ->assertNotFound();

        $this->actingAs($other)
            ->patch(route('portal.airport-transfer-bookings.cancel', $booking), [
                'reason' => 'Not my booking at all.',
                'confirm_cancellation' => '1',
            ])
            ->assertNotFound();
    }

    public function test_a_guest_request_is_never_exposed_through_the_customer_portal(): void
    {
        [$airport, $location] = $this->bookableRoute();
        $guestBooking = $this->createBooking(null, $airport, $location);
        $customer = $this->customer();

        $this->actingAs($customer)
            ->get(route('portal.airport-transfer-bookings.show', $guestBooking))
            ->assertNotFound();
    }
}
