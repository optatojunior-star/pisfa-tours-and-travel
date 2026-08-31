<?php

namespace Tests\Feature\FlightInquiries;

use App\Enums\FlightInquiryScope;
use App\Enums\FlightInquiryStatus;
use App\Enums\FlightTripType;
use App\Models\FlightInquiry;
use App\Notifications\FlightInquiries\FlightInquiryReceivedNotification;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\Feature\FlightInquiries\Concerns\BuildsFlightInquiryFixtures;
use Tests\TestCase;

class FlightInquirySubmissionTest extends TestCase
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

    public function test_the_public_form_renders_for_both_scopes(): void
    {
        $this->get(route('flight-inquiries.create'))
            ->assertOk()
            ->assertSee('Domestic and international flight enquiries')
            ->assertSee('Domestic flight')
            ->assertSee('International flight');

        foreach (FlightInquiryScope::cases() as $scope) {
            $this->get(route('flight-inquiries.create.scope', $scope->value))
                ->assertOk()
                ->assertSee($scope->description());
        }

        $this->get('/flights/charter')->assertNotFound();
    }

    public function test_a_guest_enquiry_is_stored_and_lands_on_a_signed_tracking_page(): void
    {
        $response = $this->post(route('flight-inquiries.store'), $this->inquiryPayload());

        $inquiry = FlightInquiry::query()->sole();
        $this->assertNull($inquiry->customer_id);
        $this->assertSame(FlightInquiryStatus::New, $inquiry->status);
        $this->assertNotNull($inquiry->acknowledged_at);
        $this->assertSame('guest@example.test', $inquiry->contact_email);

        $response->assertRedirectContains('/flights/enquiries/'.$inquiry->reference);
        $this->followRedirects($response)
            ->assertOk()
            ->assertSee($inquiry->reference)
            ->assertSee('No seat is held and no payment has been collected.');

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'flight_inquiry.created',
            'auditable_id' => $inquiry->getKey(),
        ]);
    }

    public function test_the_guest_tracking_page_requires_a_valid_signature(): void
    {
        $inquiry = $this->createInquiry();

        $this->get(route('flight-inquiries.guest.show', $inquiry))->assertForbidden();

        $expired = URL::temporarySignedRoute(
            'flight-inquiries.guest.show',
            now()->subMinute(),
            ['flightInquiry' => $inquiry->reference],
        );
        $this->get($expired)->assertForbidden();
    }

    public function test_a_customer_owned_enquiry_is_never_served_by_the_guest_page(): void
    {
        $customer = $this->customer();
        $inquiry = $this->createInquiry($customer);

        $signed = URL::temporarySignedRoute(
            'flight-inquiries.guest.show',
            now()->addHour(),
            ['flightInquiry' => $inquiry->reference],
        );

        $this->get($signed)->assertNotFound();
    }

    public function test_a_signed_in_customer_enquiry_uses_account_contact_details(): void
    {
        $customer = $this->customer(['name' => 'Brenda Auma', 'email' => 'brenda@example.test']);

        $response = $this->actingAs($customer)->post(
            route('flight-inquiries.store'),
            $this->inquiryPayload(overrides: [
                'contact_name' => 'Someone Else',
                'contact_email' => 'spoofed@example.test',
                'contact_phone' => '+256799999999',
            ]),
        );

        $inquiry = FlightInquiry::query()->sole();
        $this->assertSame($customer->getKey(), $inquiry->customer_id);
        $this->assertSame('brenda@example.test', $inquiry->contact_email);
        $this->assertSame('Brenda Auma', $inquiry->contact_name);

        $response->assertRedirect(route('portal.flight-inquiries.show', $inquiry));
    }

    public function test_a_return_trip_requires_a_later_return_date(): void
    {
        $outbound = CarbonImmutable::now(config('pisfa.business_timezone'))->startOfDay()->addDays(30);

        $this->post(route('flight-inquiries.store'), $this->inquiryPayload(overrides: [
            'return_on' => $outbound->subDay()->toDateString(),
        ]))->assertSessionHasErrors('return_on');

        $this->post(route('flight-inquiries.store'), $this->inquiryPayload(overrides: [
            'trip_type' => FlightTripType::Return->value,
            'return_on' => null,
        ]))->assertSessionHasErrors('return_on');

        $this->assertDatabaseCount('flight_inquiries', 0);
    }

    public function test_a_one_way_enquiry_discards_any_submitted_return_date(): void
    {
        $outbound = CarbonImmutable::now(config('pisfa.business_timezone'))->startOfDay()->addDays(30);

        $this->post(route('flight-inquiries.store'), $this->inquiryPayload(overrides: [
            'trip_type' => FlightTripType::OneWay->value,
            'return_on' => $outbound->addDays(5)->toDateString(),
        ]))->assertRedirect();

        $this->assertNull(FlightInquiry::query()->sole()->return_on);
    }

    public function test_the_outbound_date_must_sit_inside_the_planning_window(): void
    {
        $today = CarbonImmutable::now(config('pisfa.business_timezone'))->startOfDay();

        $this->post(route('flight-inquiries.store'), $this->inquiryPayload(overrides: [
            'outbound_on' => $today->toDateString(),
            'return_on' => $today->addDays(5)->toDateString(),
        ]))->assertSessionHasErrors('outbound_on');

        $this->post(route('flight-inquiries.store'), $this->inquiryPayload(overrides: [
            'outbound_on' => $today->addYears(5)->toDateString(),
            'return_on' => $today->addYears(5)->addDays(5)->toDateString(),
        ]))->assertSessionHasErrors('outbound_on');

        $this->assertDatabaseCount('flight_inquiries', 0);
    }

    public function test_the_destination_must_differ_from_the_origin(): void
    {
        $this->post(route('flight-inquiries.store'), $this->inquiryPayload(overrides: [
            'origin' => 'Entebbe',
            'destination' => 'entebbe',
        ]))->assertSessionHasErrors('destination');

        $this->assertDatabaseCount('flight_inquiries', 0);
    }

    public function test_a_missing_acknowledgement_stores_nothing(): void
    {
        $payload = $this->inquiryPayload();
        unset($payload['acknowledge_enquiry']);

        $this->post(route('flight-inquiries.store'), $payload)
            ->assertSessionHasErrors('acknowledge_enquiry');

        $this->assertDatabaseCount('flight_inquiries', 0);
    }

    public function test_repeating_an_idempotency_key_returns_the_same_enquiry(): void
    {
        $payload = $this->inquiryPayload();

        $this->post(route('flight-inquiries.store'), $payload)->assertRedirect();
        $this->post(route('flight-inquiries.store'), $payload)->assertRedirect();

        $this->assertDatabaseCount('flight_inquiries', 1);
    }

    public function test_the_traveller_receives_an_acknowledgement(): void
    {
        $customer = $this->customer();
        $this->createInquiry($customer);

        Notification::assertSentTo(
            $customer,
            FlightInquiryReceivedNotification::class,
        );
    }
}
