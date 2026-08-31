<?php

namespace Tests\Feature\CarHire;

use App\Enums\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\CarHire\Concerns\BuildsCarHireFixtures;
use Tests\TestCase;

class ContractPdfDownloadTest extends TestCase
{
    use BuildsCarHireFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo('2026-08-20 09:00:00');
    }

    public function test_the_owning_customer_downloads_a_branded_pdf_contract(): void
    {
        [$vehicle, $rate] = $this->bookableVehicle();
        $customer = $this->customer();
        $booking = $this->persistedBooking($customer, $vehicle, $rate);
        $contract = $this->contractFor($booking);

        $response = $this->actingAs($customer)
            ->get(route('portal.car-hire-bookings.contracts.download', [$booking, $contract]))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->assertStringStartsWith('%PDF-', (string) $response->getContent());
        $this->assertStringContainsString(
            'pisfa-rental-contract-'.$contract->contract_number.'.pdf',
            (string) $response->headers->get('content-disposition'),
        );
    }

    public function test_the_pdf_is_never_cached_by_a_proxy(): void
    {
        [$vehicle, $rate] = $this->bookableVehicle();
        $customer = $this->customer();
        $booking = $this->persistedBooking($customer, $vehicle, $rate);
        $contract = $this->contractFor($booking);

        $response = $this->actingAs($customer)
            ->get(route('portal.car-hire-bookings.contracts.download', [$booking, $contract]))
            ->assertOk()
            ->assertHeader('x-content-type-options', 'nosniff');

        $this->assertStringContainsString('no-store', (string) $response->headers->get('cache-control'));
    }

    public function test_a_guest_is_sent_to_login(): void
    {
        [$vehicle, $rate] = $this->bookableVehicle();
        $booking = $this->persistedBooking($this->customer(), $vehicle, $rate);
        $contract = $this->contractFor($booking);

        $this->get(route('portal.car-hire-bookings.contracts.download', [$booking, $contract]))
            ->assertRedirect(route('login'));
    }

    public function test_another_customer_cannot_reach_the_contract_pdf(): void
    {
        [$vehicle, $rate] = $this->bookableVehicle();
        $booking = $this->persistedBooking($this->customer(), $vehicle, $rate);
        $contract = $this->contractFor($booking);
        $stranger = $this->customer();

        // The scoped route binding resolves only the signed-in customer's own
        // booking, so a foreign reference is a 404 rather than a 403.
        $this->actingAs($stranger)
            ->get(route('portal.car-hire-bookings.contracts.download', [$booking, $contract]))
            ->assertNotFound();
    }

    public function test_a_contract_from_another_booking_is_not_served(): void
    {
        [$vehicle, $rate] = $this->bookableVehicle();
        $customer = $this->customer();
        $mine = $this->persistedBooking($customer, $vehicle, $rate);
        $otherBooking = $this->persistedBooking($this->customer(), $vehicle, $rate);
        $foreignContract = $this->contractFor($otherBooking);

        // Mixing a valid booking with a contract that belongs elsewhere must
        // not leak the other customer's terms.
        $this->actingAs($customer)
            ->get(route('portal.car-hire-bookings.contracts.download', [$mine, $foreignContract]))
            ->assertNotFound();
    }

    public function test_non_customer_roles_are_denied(): void
    {
        [$vehicle, $rate] = $this->bookableVehicle();
        $booking = $this->persistedBooking($this->customer(), $vehicle, $rate);
        $contract = $this->contractFor($booking);

        // The scoped customerCarHireBooking binding runs in the web group,
        // before the role middleware, so a non-customer never resolves the
        // record and receives 404 rather than a 403 that would confirm the
        // contract exists. Both outcomes deny access; 404 leaks less.
        foreach ([UserRole::Staff, UserRole::Driver] as $role) {
            $this->actingAs($this->user($role, ['two_factor_required' => false]))
                ->get(route('portal.car-hire-bookings.contracts.download', [$booking, $contract]))
                ->assertNotFound();
        }

        // The role gate itself is still proven on a route without a scoped
        // binding, so this is not silently accepting a weaker guarantee.
        $this->actingAs($this->user(UserRole::Staff, ['two_factor_required' => false]))
            ->get(route('portal.car-hire-bookings.index'))
            ->assertForbidden();
    }

    public function test_the_contract_screen_offers_the_pdf_download(): void
    {
        [$vehicle, $rate] = $this->bookableVehicle();
        $customer = $this->customer();
        $booking = $this->persistedBooking($customer, $vehicle, $rate);
        $contract = $this->contractFor($booking);

        $this->actingAs($customer)
            ->get(route('portal.car-hire-bookings.contracts.show', [$booking, $contract]))
            ->assertOk()
            ->assertSee('Download PDF')
            ->assertSee(route('portal.car-hire-bookings.contracts.download', [$booking, $contract]));
    }
}
