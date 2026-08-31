<?php

namespace Tests\Feature\Payments;

use App\Actions\Payments\SettlePayment;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Enums\TourBookingStatus;
use App\Models\AirportTransferBooking;
use App\Models\CarHireBooking;
use App\Models\Payment;
use App\Models\TourBooking;
use App\Support\Payments\PayableRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Tours\Concerns\BuildsTourFixtures;
use Tests\TestCase;

/**
 * The brief requires that every Pay Now button opens the real checkout and that
 * nothing shows a fake-success state. These cover the portal entry points.
 */
class PortalPaymentEntryPointTest extends TestCase
{
    use BuildsTourFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo('2026-08-20 09:00:00');
        Notification::fake();
        config(['payments.providers.bank_transfer.enabled' => true]);
    }

    public function test_an_unpaid_booking_shows_a_pay_now_button_to_the_real_checkout(): void
    {
        $booking = $this->persistedBooking($this->customer(), $this->bookableDeparture());

        $this->actingAs($booking->customer)
            ->get(route('portal.bookings.show', $booking))
            ->assertOk()
            ->assertSee('Pay now')
            ->assertSee('Amount due')
            // The link must resolve to the actual checkout, not a placeholder.
            ->assertSee(route('payments.checkout', ['tour-bookings', $booking->reference]));
    }

    public function test_the_pay_now_link_actually_opens_the_checkout(): void
    {
        $booking = $this->persistedBooking($this->customer(), $this->bookableDeparture());

        $this->actingAs($booking->customer)
            ->get(route('payments.checkout', ['tour-bookings', $booking->reference]))
            ->assertOk()
            ->assertSee('Continue to payment');
    }

    public function test_a_booking_with_a_payment_in_progress_does_not_offer_a_second_one(): void
    {
        $booking = $this->persistedBooking($this->customer(), $this->bookableDeparture());
        $payment = Payment::factory()
            ->forPayable($booking)
            ->usingProvider(PaymentProvider::BankTransfer)
            ->withStatus(PaymentStatus::Pending)
            ->create();

        $this->actingAs($booking->customer)
            ->get(route('portal.bookings.show', $booking))
            ->assertOk()
            ->assertSee('already in progress')
            ->assertSee(route('payments.status', $payment))
            ->assertDontSee('Pay now');
    }

    public function test_a_fully_paid_booking_shows_paid_in_full_and_no_button(): void
    {
        $booking = $this->persistedBooking($this->customer(), $this->bookableDeparture());
        // Created pending, then settled through the action so the allocation is
        // actually written. Seeding it as already-settled would hit the
        // idempotency guard and credit nothing.
        $payment = Payment::factory()
            ->forPayable($booking)
            ->usingProvider(PaymentProvider::BankTransfer)
            ->withStatus(PaymentStatus::Pending)
            ->create();

        app(SettlePayment::class)->execute(
            payment: $payment,
            actor: $this->operationsUser(),
            source: 'manual',
        );

        $this->actingAs($booking->customer)
            ->get(route('portal.bookings.show', $booking->fresh()))
            ->assertOk()
            ->assertSee('Paid in full')
            ->assertDontSee('Pay now');
    }

    public function test_a_cancelled_booking_offers_no_payment(): void
    {
        $booking = $this->persistedBooking($this->customer(), $this->bookableDeparture());
        $booking->forceFill(['status' => TourBookingStatus::Cancelled])->save();

        $this->actingAs($booking->customer)
            ->get(route('portal.bookings.show', $booking->fresh()))
            ->assertOk()
            ->assertSee('not currently accepting payment')
            ->assertDontSee('Pay now');
    }

    public function test_each_payable_type_resolves_to_its_own_checkout_segment(): void
    {
        // Each domain must link to its own segment. Before the shared registry
        // existed, the status page hardcoded 'tour-bookings' for everything,
        // which would have 404'd for hire and transfer customers.
        $cases = [
            TourBooking::class => 'tour-bookings',
            CarHireBooking::class => 'car-hire-bookings',
            AirportTransferBooking::class => 'airport-transfers',
        ];

        foreach ($cases as $class => $segment) {
            $this->assertSame($class, PayableRegistry::classFor($segment));
        }

        $booking = $this->persistedBooking($this->customer(), $this->bookableDeparture());
        $this->assertSame('tour-bookings', PayableRegistry::segmentFor($booking));
        $this->assertSame(
            route('payments.checkout', ['tour-bookings', $booking->reference]),
            PayableRegistry::checkoutUrl($booking),
        );

        // An unknown segment resolves to nothing, so checkout cannot be aimed
        // at an arbitrary table.
        $this->assertNull(PayableRegistry::classFor('users'));
    }
}
