<?php

namespace Tests\Feature\AirportTransfers;

use App\Enums\AirportTransferBookingStatus;
use App\Enums\AirportTransferType;
use App\Models\AirportTransferBooking;
use App\Models\AirportTransferRate;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\Feature\AirportTransfers\Concerns\BuildsAirportTransferFixtures;
use Tests\TestCase;

class AirportTransferPlannerHttpTest extends TestCase
{
    use BuildsAirportTransferFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo('2026-08-20 09:00:00');
    }

    public function test_public_planner_renders_without_any_search_input(): void
    {
        [$airport, $location] = $this->bookableRoute();

        $this->get(route('airport-transfers.index'))
            ->assertOk()
            ->assertSee('Price your transfer')
            ->assertSee($airport->code)
            ->assertSee($location->name)
            ->assertDontSee('Available vehicle classes');
    }

    public function test_planner_quotes_only_effective_rates_that_fit_the_party(): void
    {
        [$airport, $location] = $this->bookableRoute();
        AirportTransferRate::factory()
            ->forVehicleType('minivan', passengerCapacity: 7, luggageCapacity: 8)
            ->create([
                'airport_id' => $airport->getKey(),
                'airport_transfer_location_id' => $location->getKey(),
                'amount_minor' => 400_000,
            ]);
        AirportTransferRate::factory()
            ->forVehicleType('coaster', passengerCapacity: 25, luggageCapacity: 25)
            ->inactive()
            ->create([
                'airport_id' => $airport->getKey(),
                'airport_transfer_location_id' => $location->getKey(),
            ]);

        $response = $this->get(route('airport-transfers.index', $this->quoteQuery($airport->getKey(), $location->getKey(), passengers: 6)));

        $response->assertOk()
            ->assertSee('Available vehicle classes')
            ->assertSee('Minivan')
            // The sedan seats four, so a party of six must not be offered it.
            ->assertDontSee('>Sedan<', escape: false)
            // Inactive versions never reach the public planner.
            ->assertDontSee('Coaster');
    }

    public function test_planner_shows_an_empty_state_when_no_rate_matches(): void
    {
        [$airport, $location] = $this->bookableRoute();

        $this->get(route('airport-transfers.index', $this->quoteQuery(
            $airport->getKey(),
            $location->getKey(),
            passengers: 40,
        )))
            ->assertOk()
            ->assertSee('No published rate matches this request')
            ->assertSee(route('request-quotation', ['service' => 'airport-transfers']));
    }

    public function test_guest_submission_creates_a_request_and_lands_on_a_signed_acknowledgement(): void
    {
        Notification::fake();
        [$airport, $location] = $this->bookableRoute();

        $response = $this->post(
            route('airport-transfer-bookings.store'),
            $this->storePayload($airport->getKey(), $location->getKey()),
        );

        $booking = AirportTransferBooking::query()->sole();
        $this->assertNull($booking->customer_id);
        $this->assertSame(AirportTransferBookingStatus::Pending, $booking->status);
        $this->assertSame('guest@example.test', $booking->contact_email);

        $response->assertRedirectContains('/airport-transfers/acknowledgement/'.$booking->reference);
        $response->assertSessionHas('success');

        $this->followRedirects($response)
            ->assertOk()
            ->assertSee($booking->reference)
            ->assertSee('Not collected online');
    }

    public function test_guest_acknowledgement_requires_a_valid_signature(): void
    {
        [$airport, $location] = $this->bookableRoute();
        $booking = $this->createBooking(null, $airport, $location);

        $this->get(route('airport-transfer-bookings.guest.show', $booking))->assertForbidden();

        $expired = URL::temporarySignedRoute(
            'airport-transfer-bookings.guest.show',
            now()->subMinute(),
            ['airportTransferBooking' => $booking->reference],
        );
        $this->get($expired)->assertForbidden();
    }

    public function test_signed_acknowledgement_is_not_reachable_for_an_owned_booking(): void
    {
        [$airport, $location] = $this->bookableRoute();
        $customer = $this->customer();
        $booking = $this->createBooking($customer, $airport, $location);

        $signed = URL::temporarySignedRoute(
            'airport-transfer-bookings.guest.show',
            now()->addHour(),
            ['airportTransferBooking' => $booking->reference],
        );

        // A customer-owned request belongs in the authenticated portal, so the
        // guest surface must not leak it even with a valid signature.
        $this->get($signed)->assertNotFound();
    }

    public function test_signed_in_customer_submission_uses_account_contact_details_and_lands_in_the_portal(): void
    {
        Notification::fake();
        [$airport, $location] = $this->bookableRoute();
        $customer = $this->customer(['name' => 'Aisha Nakato', 'email' => 'aisha@example.test']);

        $response = $this->actingAs($customer)->post(
            route('airport-transfer-bookings.store'),
            $this->storePayload($airport->getKey(), $location->getKey()),
        );

        $booking = AirportTransferBooking::query()->sole();
        $this->assertSame($customer->getKey(), $booking->customer_id);
        // Server-owned contact fields win over anything the form submitted.
        $this->assertSame('aisha@example.test', $booking->contact_email);
        $this->assertSame('Aisha Nakato', $booking->contact_name);

        $response->assertRedirect(route('portal.airport-transfer-bookings.show', $booking));
    }

    public function test_submission_rejects_a_missing_acknowledgement_and_stores_nothing(): void
    {
        [$airport, $location] = $this->bookableRoute();
        $payload = $this->storePayload($airport->getKey(), $location->getKey());
        unset($payload['acknowledge_request']);

        $this->post(route('airport-transfer-bookings.store'), $payload)
            ->assertSessionHasErrors('acknowledge_request');

        $this->assertDatabaseCount('airport_transfer_bookings', 0);
    }

    public function test_repeating_an_idempotency_key_returns_the_same_request(): void
    {
        Notification::fake();
        [$airport, $location] = $this->bookableRoute();
        $payload = $this->storePayload($airport->getKey(), $location->getKey());

        $this->post(route('airport-transfer-bookings.store'), $payload)->assertRedirect();
        $this->post(route('airport-transfer-bookings.store'), $payload)->assertRedirect();

        $this->assertDatabaseCount('airport_transfer_bookings', 1);
    }

    public function test_an_inactive_airport_cannot_be_booked_through_the_public_form(): void
    {
        [$airport, $location] = $this->bookableRoute(['is_active' => false]);

        $this->post(
            route('airport-transfer-bookings.store'),
            $this->storePayload($airport->getKey(), $location->getKey()),
        )->assertSessionHasErrors('airport_id');

        $this->assertDatabaseCount('airport_transfer_bookings', 0);
    }

    /** @return array<string, mixed> */
    private function quoteQuery(int $airportId, int $locationId, int $passengers = 2): array
    {
        return [
            'transfer_type' => AirportTransferType::Pickup->value,
            'airport_id' => $airportId,
            'airport_transfer_location_id' => $locationId,
            'currency' => 'UGX',
            'flight_scheduled_at' => $this->localFormValue(),
            'passenger_count' => $passengers,
            'luggage_count' => 1,
        ];
    }

    /** @return array<string, mixed> */
    private function storePayload(int $airportId, int $locationId): array
    {
        return [
            'idempotency_key' => '2b2c1e8a-1c2c-4a9e-9c1a-3f8d2c9b7a10',
            'transfer_type' => AirportTransferType::Pickup->value,
            'airport_id' => $airportId,
            'airport_transfer_location_id' => $locationId,
            'vehicle_type' => 'sedan',
            'currency' => 'UGX',
            'flight_number' => 'KQ 101',
            'flight_scheduled_at' => $this->localFormValue(),
            'passenger_count' => 2,
            'luggage_count' => 1,
            'service_address' => 'Plot 10 Kampala Road, Kampala',
            'contact_name' => 'Guest Traveller',
            'contact_email' => 'guest@example.test',
            'contact_phone' => '+256701234567',
            'acknowledge_request' => '1',
        ];
    }

    private function localFormValue(int $daysAhead = 10): string
    {
        return CarbonImmutable::now((string) config('pisfa.business_timezone', 'Africa/Kampala'))
            ->addDays($daysAhead)
            ->startOfHour()
            ->format('Y-m-d\TH:i');
    }
}
