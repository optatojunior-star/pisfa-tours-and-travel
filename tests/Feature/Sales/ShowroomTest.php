<?php

namespace Tests\Feature\Sales;

use App\Actions\Sales\SaveVehicleListing;
use App\Actions\Sales\SubmitSalesEnquiry;
use App\Actions\Sales\TransitionSalesEnquiry;
use App\Actions\Sales\TransitionVehicleListing;
use App\Enums\AccountStatus;
use App\Enums\CarHireBookingStatus;
use App\Enums\ListingStatus;
use App\Enums\SalesEnquiryStatus;
use App\Enums\UserRole;
use App\Enums\VehicleCatalogueStatus;
use App\Enums\VehicleOperationalStatus;
use App\Http\Middleware\EnsureTwoFactorAuthenticationIsConfigured;
use App\Models\CarHireBooking;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleListing;
use App\Models\VehicleSalesEnquiry;
use App\Notifications\Sales\SalesEnquiryReceivedNotification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ShowroomTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo('2026-08-20 09:00:00');
        Notification::fake();
    }

    private function user(UserRole $role, array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'role' => $role,
            'status' => AccountStatus::Active,
            'email_verified_at' => now(),
            'phone' => '+256700'.fake()->unique()->numerify('######'),
        ], $attributes));
    }

    private function staff(): User
    {
        return $this->user(UserRole::Staff, ['two_factor_required' => false]);
    }

    /**
     * Managers always need two-factor authentication — the role is in
     * `security.two_factor.required_roles`, so the column cannot opt out of it.
     */
    private function manager(): User
    {
        return $this->user(UserRole::Manager, [
            'two_factor_secret' => 'configured-for-feature-test',
            'two_factor_confirmed_at' => now(),
        ]);
    }

    /**
     * The session a manager has after completing the two-factor challenge.
     *
     * @return array<string, int>
     */
    private function verifiedSession(User $manager): array
    {
        return [
            EnsureTwoFactorAuthenticationIsConfigured::VERIFIED_AT_SESSION_KEY => now()->timestamp,
            EnsureTwoFactorAuthenticationIsConfigured::VERIFIED_USER_SESSION_KEY => $manager->id,
        ];
    }

    private function customer(): User
    {
        return $this->user(UserRole::Customer);
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title' => '2016 Toyota Land Cruiser Prado TX',
            'make' => 'Toyota',
            'model' => 'Land Cruiser Prado',
            'year' => 2016,
            'body_type' => 'SUV',
            'fuel_type' => 'Diesel',
            'transmission' => 'Automatic',
            'colour' => 'Pearl white',
            'mileage_km' => 96000,
            'seating_capacity' => 7,
            'condition' => 'Used — good',
            'description' => str_repeat('Serviced at the Toyota Uganda main dealer every 5,000 km. ', 6),
            'asking_price' => '145000000',
            'currency' => 'UGX',
            'is_negotiable' => true,
        ], $overrides);
    }

    private function enquiryPayload(array $overrides = []): array
    {
        return array_merge([
            'contact_name' => 'Sarah Nabirye',
            'contact_email' => 'sarah@example.com',
            'contact_phone' => '+256700111222',
            'message' => 'Could I come and see it on Saturday?',
        ], $overrides);
    }

    // ---- Public visibility -----------------------------------------------

    public function test_the_showroom_lists_only_publicly_visible_listings(): void
    {
        $available = VehicleListing::factory()->available()->create(['title' => 'Available Prado']);
        $reserved = VehicleListing::factory()->reserved()->create(['title' => 'Reserved Harrier']);
        VehicleListing::factory()->create(['title' => 'Draft Hilux']);
        VehicleListing::factory()->withdrawn()->create(['title' => 'Withdrawn Forester']);

        $this->get(route('showroom.index'))
            ->assertOk()
            ->assertSee($available->title)
            ->assertSee($reserved->title)
            ->assertDontSee('Draft Hilux')
            ->assertDontSee('Withdrawn Forester');
    }

    public function test_a_draft_listing_is_not_reachable_by_its_slug(): void
    {
        $draft = VehicleListing::factory()->create();

        $this->get(route('showroom.show', $draft->slug))->assertNotFound();
    }

    public function test_a_withdrawn_listing_is_not_reachable_by_its_slug(): void
    {
        $withdrawn = VehicleListing::factory()->withdrawn()->create();

        $this->get(route('showroom.show', $withdrawn->slug))->assertNotFound();
    }

    public function test_a_recently_sold_listing_stays_visible_but_an_old_one_does_not(): void
    {
        $days = (int) config('sales.showroom.sold_visible_days');

        $recent = VehicleListing::factory()
            ->sold(null, now()->subDays(max(0, $days - 2))->toDateTimeString())
            ->create(['title' => 'Recently sold Prado']);

        $old = VehicleListing::factory()
            ->sold(null, now()->subDays($days + 10)->toDateTimeString())
            ->create(['title' => 'Long sold Harrier']);

        $this->get(route('showroom.index'))
            ->assertOk()
            ->assertSee($recent->title)
            ->assertDontSee($old->title);

        // Still reachable directly, so an old link is not a dead end.
        $this->get(route('showroom.show', $old->slug))->assertOk();
    }

    public function test_a_sold_listing_offers_no_enquiry_form(): void
    {
        $sold = VehicleListing::factory()->sold()->create();

        $this->get(route('showroom.show', $sold->slug))
            ->assertOk()
            ->assertSee('has been sold')
            ->assertDontSee('Send enquiry');
    }

    public function test_the_showroom_price_filter_ignores_an_unparseable_amount(): void
    {
        $listing = VehicleListing::factory()->available()->priced(50_000_000)->create();

        $this->get(route('showroom.index', ['min_price' => 'not a number']))
            ->assertOk()
            ->assertSee($listing->title);
    }

    public function test_the_price_filter_only_matches_listings_in_that_currency(): void
    {
        $shillings = VehicleListing::factory()->available()->priced(50_000_000, 'UGX')->create(['title' => 'Shilling car']);
        $dollars = VehicleListing::factory()->available()->priced(1_500_000, 'USD')->create(['title' => 'Dollar car']);

        $this->get(route('showroom.index', ['currency' => 'USD', 'min_price' => '1000']))
            ->assertOk()
            ->assertSee($dollars->title)
            ->assertDontSee($shillings->title);
    }

    // ---- Creating a listing -----------------------------------------------

    public function test_staff_can_create_a_standalone_listing_as_a_draft(): void
    {
        $staff = $this->staff();

        $listing = app(SaveVehicleListing::class)->create($staff, $this->payload());

        $this->assertSame(ListingStatus::Draft, $listing->status);
        $this->assertNull($listing->vehicle_id);
        // UGX has no minor unit, so the integer is whole shillings.
        $this->assertSame(145_000_000, $listing->asking_price_minor);
        $this->assertSame($staff->getKey(), $listing->created_by_user_id);
        $this->assertDatabaseHas('audit_logs', ['event' => 'vehicle_listing.created']);
    }

    public function test_a_customer_cannot_create_a_listing(): void
    {
        $this->expectException(AuthorizationException::class);

        app(SaveVehicleListing::class)->create($this->customer(), $this->payload());
    }

    public function test_a_listing_cannot_be_created_for_nothing(): void
    {
        $this->expectException(ValidationException::class);

        app(SaveVehicleListing::class)->create($this->staff(), $this->payload(['asking_price' => '0']));
    }

    public function test_the_specification_is_snapshotted_not_read_through_the_vehicle(): void
    {
        $staff = $this->staff();
        $vehicle = Vehicle::factory()->create(['make' => 'Toyota', 'model' => 'Prado', 'current_odometer_km' => 96_000]);

        $listing = app(SaveVehicleListing::class)->create($staff, $this->payload(), $vehicle);

        $vehicle->forceFill(['make' => 'Nissan', 'current_odometer_km' => 200_000])->save();

        $this->assertSame('Toyota', $listing->fresh()->make);
        $this->assertSame(96_000, $listing->fresh()->mileage_km);
    }

    public function test_a_vehicle_with_unfinished_hire_bookings_cannot_be_listed(): void
    {
        $staff = $this->staff();
        $vehicle = Vehicle::factory()->create();

        CarHireBooking::factory()->create([
            'vehicle_id' => $vehicle->getKey(),
            'status' => CarHireBookingStatus::Confirmed,
            'return_at' => now()->addDays(4),
        ]);

        $this->expectException(ValidationException::class);

        app(SaveVehicleListing::class)->create($staff, $this->payload(), $vehicle);
    }

    public function test_a_vehicle_already_in_the_showroom_cannot_be_listed_twice(): void
    {
        $staff = $this->staff();
        $vehicle = Vehicle::factory()->create();

        VehicleListing::factory()->forVehicle($vehicle)->available()->create();

        $this->expectException(ValidationException::class);

        app(SaveVehicleListing::class)->create($staff, $this->payload(), $vehicle);
    }

    public function test_a_vehicle_whose_previous_listing_was_withdrawn_can_be_listed_again(): void
    {
        $staff = $this->staff();
        $vehicle = Vehicle::factory()->create();

        VehicleListing::factory()->forVehicle($vehicle)->withdrawn()->create();

        $listing = app(SaveVehicleListing::class)->create($staff, $this->payload(), $vehicle);

        $this->assertSame($vehicle->getKey(), $listing->vehicle_id);
    }

    public function test_a_sold_listing_cannot_be_edited(): void
    {
        $manager = $this->manager();
        $listing = VehicleListing::factory()->sold()->create();

        $this->expectException(ValidationException::class);

        app(SaveVehicleListing::class)->update($manager, $listing, $this->payload(['asking_price' => '1']));
    }

    public function test_a_withdrawn_slug_is_never_reused(): void
    {
        $staff = $this->staff();

        $first = app(SaveVehicleListing::class)->create($staff, $this->payload());
        $first->delete();

        $second = app(SaveVehicleListing::class)->create($staff, $this->payload());

        $this->assertNotSame($first->slug, $second->slug);
    }

    // ---- The sale ---------------------------------------------------------

    public function test_every_listing_status_is_reachable(): void
    {
        $manager = $this->manager();
        $transition = app(TransitionVehicleListing::class);
        $save = app(SaveVehicleListing::class);

        $draft = $save->create($manager, $this->payload());
        $this->assertSame(ListingStatus::Draft, $draft->status);

        $available = $transition->list($manager, $draft);
        $this->assertSame(ListingStatus::Available, $available->status);

        $reserved = $transition->reserve($manager, $available);
        $this->assertSame(ListingStatus::Reserved, $reserved->status);

        $released = $transition->release($manager, $reserved);
        $this->assertSame(ListingStatus::Available, $released->status);

        $withdrawn = $transition->withdraw($manager, $released, 'Going back into the hire fleet');
        $this->assertSame(ListingStatus::Withdrawn, $withdrawn->status);

        $restored = $transition->restore($manager, $withdrawn);
        $this->assertSame(ListingStatus::Draft, $restored->status);
        $this->assertNull($restored->closure_reason);

        $relisted = $transition->list($manager, $restored);
        $sold = $transition->sell($manager, $relisted, '140000000');
        $this->assertSame(ListingStatus::Sold, $sold->status);
    }

    public function test_staff_cannot_record_a_sale(): void
    {
        $listing = VehicleListing::factory()->available()->create();

        $this->expectException(AuthorizationException::class);

        app(TransitionVehicleListing::class)->sell($this->staff(), $listing, '140000000');
    }

    public function test_selling_a_fleet_vehicle_retires_it_from_hire(): void
    {
        $manager = $this->manager();
        $vehicle = Vehicle::factory()->create([
            'catalogue_status' => VehicleCatalogueStatus::Published,
            'operational_status' => VehicleOperationalStatus::Available,
        ]);
        $listing = VehicleListing::factory()->forVehicle($vehicle)->available()->create();

        app(TransitionVehicleListing::class)->sell($manager, $listing, '140000000');

        $vehicle->refresh();

        $this->assertSame(VehicleCatalogueStatus::Archived, $vehicle->catalogue_status);
        $this->assertSame(VehicleOperationalStatus::Retired, $vehicle->operational_status);
    }

    public function test_a_sale_is_final(): void
    {
        $manager = $this->manager();
        $listing = VehicleListing::factory()->sold()->create();

        $this->assertSame([], $listing->status->allowedTransitions());

        $this->expectException(ValidationException::class);

        app(TransitionVehicleListing::class)->withdraw($manager, $listing, 'Sold in error');
    }

    public function test_selling_twice_is_idempotent_and_keeps_the_first_price(): void
    {
        $manager = $this->manager();
        $listing = VehicleListing::factory()->available()->create();
        $action = app(TransitionVehicleListing::class);

        $first = $action->sell($manager, $listing, '140000000');
        $second = $action->sell($manager, $first, '999000000');

        $this->assertSame(140_000_000, $second->sold_price_minor);
    }

    public function test_a_sale_closes_the_buyer_as_won_and_the_others_as_lost(): void
    {
        $manager = $this->manager();
        $listing = VehicleListing::factory()->available()->create();

        $buyer = VehicleSalesEnquiry::factory()->for_($listing)
            ->status(SalesEnquiryStatus::Negotiating)->create();
        $other = VehicleSalesEnquiry::factory()->for_($listing)->create();

        app(TransitionVehicleListing::class)->sell($manager, $listing, '140000000', $buyer);

        $this->assertSame(SalesEnquiryStatus::Won, $buyer->fresh()->status);
        $this->assertSame(SalesEnquiryStatus::Lost, $other->fresh()->status);
        $this->assertNotNull($other->fresh()->closed_at);
    }

    public function test_a_buyer_from_another_listing_is_refused(): void
    {
        $manager = $this->manager();
        $listing = VehicleListing::factory()->available()->create();
        $stranger = VehicleSalesEnquiry::factory()->create();

        $this->expectException(ValidationException::class);

        app(TransitionVehicleListing::class)->sell($manager, $listing, '140000000', $stranger);
    }

    // ---- Enquiries ---------------------------------------------------------

    public function test_a_guest_may_enquire_without_an_account(): void
    {
        $listing = VehicleListing::factory()->available()->create();

        $enquiry = app(SubmitSalesEnquiry::class)
            ->execute(null, $listing, $this->enquiryPayload(), (string) Str::uuid());

        $this->assertNull($enquiry->customer_id);
        $this->assertTrue($enquiry->isGuest());
        $this->assertSame(SalesEnquiryStatus::New, $enquiry->status);
    }

    public function test_a_signed_in_customer_cannot_spoof_their_identity(): void
    {
        $customer = $this->customer();
        $listing = VehicleListing::factory()->available()->create();

        $enquiry = app(SubmitSalesEnquiry::class)->execute(
            $customer,
            $listing,
            $this->enquiryPayload(['contact_email' => 'someone.else@example.com']),
            (string) Str::uuid(),
        );

        $this->assertSame($customer->email, $enquiry->contact_email);
        $this->assertSame($customer->getKey(), $enquiry->customer_id);
    }

    public function test_replaying_the_same_key_returns_the_same_enquiry(): void
    {
        $listing = VehicleListing::factory()->available()->create();
        $key = (string) Str::uuid();
        $action = app(SubmitSalesEnquiry::class);

        $first = $action->execute(null, $listing, $this->enquiryPayload(), $key);
        $second = $action->execute(null, $listing, $this->enquiryPayload(), $key);

        $this->assertTrue($first->is($second));
        $this->assertSame(1, VehicleSalesEnquiry::query()->count());
    }

    public function test_the_same_person_asking_about_two_cars_files_two_enquiries(): void
    {
        $first = VehicleListing::factory()->available()->create();
        $second = VehicleListing::factory()->available()->create();
        $key = (string) Str::uuid();
        $action = app(SubmitSalesEnquiry::class);

        $action->execute(null, $first, $this->enquiryPayload(), $key);
        $action->execute(null, $second, $this->enquiryPayload(), $key);

        $this->assertSame(2, VehicleSalesEnquiry::query()->count());
    }

    public function test_one_guests_replay_cannot_collide_with_another_guests(): void
    {
        $listing = VehicleListing::factory()->available()->create();
        $key = (string) Str::uuid();
        $action = app(SubmitSalesEnquiry::class);

        $action->execute(null, $listing, $this->enquiryPayload(), $key);
        // A different guest replaying the same key is a different owner hash.
        $action->execute(null, $listing, $this->enquiryPayload([
            'contact_email' => 'another@example.com',
            'contact_phone' => '+256700999888',
        ]), $key);

        $this->assertSame(2, VehicleSalesEnquiry::query()->count());
    }

    public function test_the_database_refuses_a_duplicate_owner_and_key_pair(): void
    {
        $listing = VehicleListing::factory()->available()->create();
        $existing = VehicleSalesEnquiry::factory()->for_($listing)->create();

        $this->expectException(QueryException::class);

        VehicleSalesEnquiry::factory()->for_($listing)->create([
            'idempotency_owner_hash' => $existing->idempotency_owner_hash,
            'idempotency_key' => $existing->idempotency_key,
        ]);
    }

    public function test_a_sold_listing_takes_no_further_enquiries(): void
    {
        $listing = VehicleListing::factory()->sold()->create();

        $this->expectException(ValidationException::class);

        app(SubmitSalesEnquiry::class)->execute(null, $listing, $this->enquiryPayload(), (string) Str::uuid());
    }

    public function test_a_reserved_listing_still_takes_enquiries(): void
    {
        $listing = VehicleListing::factory()->reserved()->create();

        $enquiry = app(SubmitSalesEnquiry::class)
            ->execute(null, $listing, $this->enquiryPayload(), (string) Str::uuid());

        $this->assertSame(SalesEnquiryStatus::New, $enquiry->status);
    }

    public function test_an_offer_of_nothing_is_refused(): void
    {
        $listing = VehicleListing::factory()->available()->create();

        $this->expectException(ValidationException::class);

        app(SubmitSalesEnquiry::class)->execute(
            null,
            $listing,
            $this->enquiryPayload(['offer' => '0', 'offer_currency' => 'UGX']),
            (string) Str::uuid(),
        );
    }

    public function test_an_enquiry_notifies_the_enquirer(): void
    {
        $listing = VehicleListing::factory()->available()->create();

        app(SubmitSalesEnquiry::class)->execute(null, $listing, $this->enquiryPayload(), (string) Str::uuid());

        Notification::assertSentOnDemand(SalesEnquiryReceivedNotification::class);
    }

    // ---- The pipeline ------------------------------------------------------

    public function test_an_enquiry_cannot_be_won_on_its_own(): void
    {
        $enquiry = VehicleSalesEnquiry::factory()->status(SalesEnquiryStatus::Negotiating)->create();

        $this->expectException(ValidationException::class);

        app(TransitionSalesEnquiry::class)->advance($this->manager(), $enquiry, SalesEnquiryStatus::Won);
    }

    public function test_losing_an_enquiry_requires_a_reason(): void
    {
        $enquiry = VehicleSalesEnquiry::factory()->create();

        $this->expectException(ValidationException::class);

        app(TransitionSalesEnquiry::class)->advance($this->manager(), $enquiry, SalesEnquiryStatus::Lost);
    }

    public function test_an_enquiry_cannot_skip_the_pipeline(): void
    {
        $enquiry = VehicleSalesEnquiry::factory()->create();

        $this->expectException(ValidationException::class);

        app(TransitionSalesEnquiry::class)->advance($this->staff(), $enquiry, SalesEnquiryStatus::Negotiating);
    }

    public function test_an_enquiry_cannot_be_assigned_to_somebody_without_console_access(): void
    {
        $enquiry = VehicleSalesEnquiry::factory()->create();

        $this->expectException(ValidationException::class);

        app(TransitionSalesEnquiry::class)->assign($this->manager(), $enquiry, $this->customer());
    }

    public function test_a_note_is_stamped_and_appended(): void
    {
        $staff = $this->staff();
        $enquiry = VehicleSalesEnquiry::factory()->create();
        $action = app(TransitionSalesEnquiry::class);

        $action->note($staff, $enquiry, 'Called — no answer.');
        $updated = $action->note($staff, $enquiry->fresh(), 'Called again, coming Saturday.');

        $this->assertStringContainsString('Called — no answer.', (string) $updated->internal_notes);
        $this->assertStringContainsString('Called again', (string) $updated->internal_notes);
        $this->assertStringContainsString($staff->name, (string) $updated->internal_notes);
    }

    public function test_the_note_body_is_never_written_to_the_audit_trail(): void
    {
        $enquiry = VehicleSalesEnquiry::factory()->create();

        app(TransitionSalesEnquiry::class)->note($this->staff(), $enquiry, 'Floor price is 130m, do not go below.');

        $this->assertDatabaseHas('audit_logs', ['event' => 'sales_enquiry.note_added']);
        $this->assertDatabaseMissing('audit_logs', ['new_values' => json_encode(['note' => 'Floor price is 130m, do not go below.'])]);
    }

    public function test_internal_notes_are_never_serialised(): void
    {
        $listing = VehicleListing::factory()->available()->create(['internal_notes' => 'Floor price 130m']);
        $enquiry = VehicleSalesEnquiry::factory()->for_($listing)->create(['internal_notes' => 'Time waster']);

        $this->assertArrayNotHasKey('internal_notes', $listing->toArray());
        $this->assertArrayNotHasKey('internal_notes', $enquiry->toArray());
        $this->assertArrayNotHasKey('idempotency_key', $enquiry->toArray());
    }

    // ---- HTTP surfaces ------------------------------------------------------

    public function test_a_guest_can_submit_the_enquiry_form(): void
    {
        $listing = VehicleListing::factory()->available()->create();

        $this->post(route('showroom.enquire', $listing->slug), $this->enquiryPayload([
            'idempotency_key' => (string) Str::uuid(),
        ]))->assertRedirect(route('showroom.show', $listing->slug))
            ->assertSessionHas('success');

        $this->assertSame(1, VehicleSalesEnquiry::query()->count());
    }

    public function test_the_enquiry_form_requires_contact_details_from_a_guest(): void
    {
        $listing = VehicleListing::factory()->available()->create();

        $this->post(route('showroom.enquire', $listing->slug), ['idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasErrors(['contact_name', 'contact_email', 'contact_phone']);
    }

    public function test_the_console_is_closed_to_customers(): void
    {
        $this->actingAs($this->customer())
            ->get(route('admin.showroom.index'))
            ->assertForbidden();
    }

    public function test_the_console_is_closed_to_guests(): void
    {
        $this->get(route('admin.showroom.index'))->assertRedirect(route('login'));
    }

    public function test_staff_can_work_the_console(): void
    {
        $staff = $this->staff();
        $listing = VehicleListing::factory()->available()->create();
        VehicleSalesEnquiry::factory()->for_($listing)->create();

        $this->actingAs($staff)->get(route('admin.showroom.index'))->assertOk()->assertSee($listing->title);
        $this->actingAs($staff)->get(route('admin.showroom.show', $listing))->assertOk();
        $this->actingAs($staff)->get(route('admin.showroom.create'))->assertOk();
        $this->actingAs($staff)->get(route('admin.showroom.enquiries.index'))->assertOk();
    }

    public function test_staff_see_no_sale_form_but_a_manager_does(): void
    {
        $listing = VehicleListing::factory()->available()->create();
        $manager = $this->manager();

        $this->actingAs($this->staff())
            ->get(route('admin.showroom.show', $listing))
            ->assertOk()
            ->assertSee('A manager has to record the sale');

        $this->actingAs($manager)
            ->withSession($this->verifiedSession($manager))
            ->get(route('admin.showroom.show', $listing))
            ->assertOk()
            ->assertSee('Record the sale')
            ->assertDontSee('A manager has to record the sale');
    }

    public function test_the_console_creates_a_listing_end_to_end(): void
    {
        $this->actingAs($this->staff())
            ->post(route('admin.showroom.store'), $this->payload())
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(1, VehicleListing::query()->count());
    }

    public function test_a_manager_records_a_sale_through_the_console(): void
    {
        $listing = VehicleListing::factory()->available()->create();
        $manager = $this->manager();

        $this->actingAs($manager)
            ->withSession($this->verifiedSession($manager))
            ->post(route('admin.showroom.sell', $listing), ['sold_price' => '138000000'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(ListingStatus::Sold, $listing->fresh()->status);
    }

    public function test_a_customer_may_read_their_own_enquiry_but_not_another(): void
    {
        $customer = $this->customer();
        $mine = VehicleSalesEnquiry::factory()->fromCustomer($customer)->create();
        $theirs = VehicleSalesEnquiry::factory()->create();

        $this->assertTrue($customer->can('view', $mine));
        $this->assertFalse($customer->can('view', $theirs));
    }
}
