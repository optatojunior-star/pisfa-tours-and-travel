<?php

namespace Tests\Feature\FlightInquiries;

use App\Enums\AccountStatus;
use App\Enums\FlightInquiryEntryType;
use App\Enums\FlightInquiryStatus;
use App\Enums\UserRole;
use App\Models\FlightInquiry;
use App\Models\FlightInquiryEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\FlightInquiries\Concerns\BuildsFlightInquiryFixtures;
use Tests\TestCase;

class FlightInquiryAuthorizationTest extends TestCase
{
    use BuildsFlightInquiryFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo('2026-08-20 09:00:00');
        Notification::fake();
    }

    public function test_the_public_form_stays_open_while_portal_and_console_require_sign_in(): void
    {
        $this->get(route('flight-inquiries.create'))->assertOk();

        foreach ([
            route('portal.flight-inquiries.index'),
            route('admin.flight-inquiries.index'),
        ] as $url) {
            $this->get($url)->assertRedirect(route('login'));
        }
    }

    public function test_an_unverified_customer_is_sent_to_email_verification(): void
    {
        $unverified = $this->customer(['email_verified_at' => null]);

        $this->actingAs($unverified)
            ->get(route('portal.flight-inquiries.index'))
            ->assertRedirect(route('verification.notice'));
    }

    public function test_non_customer_roles_are_denied_the_customer_portal(): void
    {
        foreach ([UserRole::Staff, UserRole::Manager, UserRole::Driver, UserRole::SuperAdmin] as $role) {
            $actor = $this->user($role, ['two_factor_required' => false]);

            $this->actingAs($actor)
                ->get(route('portal.flight-inquiries.index'))
                ->assertForbidden();
        }
    }

    public function test_customers_and_drivers_are_denied_the_operations_console(): void
    {
        $inquiry = $this->createInquiry();

        foreach ([$this->customer(), $this->user(UserRole::Driver)] as $actor) {
            $this->actingAs($actor)->get(route('admin.flight-inquiries.index'))->assertForbidden();
            $this->actingAs($actor)->get(route('admin.flight-inquiries.show', $inquiry))->assertForbidden();
            $this->actingAs($actor)
                ->patch(route('admin.flight-inquiries.transition', $inquiry), [
                    'status' => FlightInquiryStatus::Contacted->value,
                ])
                ->assertForbidden();
        }
    }

    public function test_operations_staff_reach_the_console(): void
    {
        $staff = $this->operationsUser();

        $this->actingAs($staff)
            ->get(route('admin.flight-inquiries.index'))
            ->assertOk()
            ->assertSee('Flight enquiries');
    }

    public function test_an_inactive_account_is_stopped_before_authorization(): void
    {
        $inactive = $this->customer(['status' => AccountStatus::Suspended]);

        $this->actingAs($inactive)
            ->get(route('portal.flight-inquiries.index'))
            ->assertRedirect(route('login'));
    }

    public function test_a_customer_sees_only_their_own_enquiries(): void
    {
        $owner = $this->customer();
        $other = $this->customer();
        $mine = $this->createInquiry($owner);
        $theirs = $this->createInquiry($other);

        $this->actingAs($owner)
            ->get(route('portal.flight-inquiries.index'))
            ->assertOk()
            ->assertSee($mine->reference)
            ->assertDontSee($theirs->reference);

        $this->actingAs($owner)
            ->get(route('portal.flight-inquiries.show', $theirs))
            ->assertNotFound();
    }

    public function test_a_guest_enquiry_is_not_reachable_from_the_customer_portal(): void
    {
        $guestInquiry = $this->createInquiry();
        $customer = $this->customer();

        $this->actingAs($customer)
            ->get(route('portal.flight-inquiries.show', $guestInquiry))
            ->assertNotFound();
    }

    public function test_the_portal_shows_communications_but_never_internal_notes(): void
    {
        $customer = $this->customer();
        $staff = $this->operationsUser();
        $inquiry = $this->createInquiry($customer);

        FlightInquiryEntry::factory()->ofType(FlightInquiryEntryType::InternalNote)->create([
            'flight_inquiry_id' => $inquiry->getKey(),
            'author_user_id' => $staff->getKey(),
            'body' => 'Margin is thin on this route, hold at published fare.',
        ]);
        FlightInquiryEntry::factory()->ofType(FlightInquiryEntryType::EmailSent)->create([
            'flight_inquiry_id' => $inquiry->getKey(),
            'author_user_id' => $staff->getKey(),
            'body' => 'Sent three fare options for the outbound date.',
        ]);

        $response = $this->actingAs($customer)
            ->get(route('portal.flight-inquiries.show', $inquiry))
            ->assertOk();

        $response->assertSee('Sent three fare options for the outbound date.');
        $response->assertDontSee('Margin is thin on this route');

        // The same entry is visible to operations.
        $this->actingAs($staff)
            ->get(route('admin.flight-inquiries.show', $inquiry))
            ->assertOk()
            ->assertSee('Margin is thin on this route, hold at published fare.');
    }

    public function test_the_portal_shows_an_empty_state_before_any_enquiry(): void
    {
        $customer = $this->customer();

        $this->actingAs($customer)
            ->get(route('portal.flight-inquiries.index'))
            ->assertOk()
            ->assertSee('No flight enquiries yet')
            ->assertSee(route('flight-inquiries.create'));
    }

    public function test_a_customer_cannot_change_status_or_add_entries_through_the_console_routes(): void
    {
        $customer = $this->customer();
        $inquiry = $this->createInquiry($customer);

        $this->actingAs($customer)
            ->post(route('admin.flight-inquiries.entries.store', $inquiry), [
                'entry_type' => FlightInquiryEntryType::InternalNote->value,
                'body' => 'Trying to write into the operations trail.',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('flight_inquiry_entries', 0);
    }

    public function test_managers_and_super_admins_must_configure_two_factor_first(): void
    {
        foreach ([UserRole::Manager, UserRole::SuperAdmin] as $role) {
            $actor = $this->user($role, ['two_factor_required' => false]);

            $this->actingAs($actor)
                ->get(route('admin.flight-inquiries.index'))
                ->assertRedirect(route('profile.security'));
        }
    }

    public function test_a_staff_member_sees_no_reopen_option_on_a_resolved_inquiry(): void
    {
        $staff = $this->operationsUser();
        $inquiry = FlightInquiry::factory()->withStatus(FlightInquiryStatus::Closed)->create();

        $this->actingAs($staff)
            ->get(route('admin.flight-inquiries.show', $inquiry))
            ->assertOk()
            ->assertSee('restricted to managers and super administrators')
            ->assertDontSee('Reopen (New)');
    }
}
