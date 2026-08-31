<?php

namespace Tests\Feature\Payments;

use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Enums\TourBookingStatus;
use App\Enums\UserRole;
use App\Models\Payment;
use App\Models\TourBooking;
use App\Services\Payments\Gateways\FakeGateway;
use App\Services\Payments\PaymentGatewayRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Feature\Tours\Concerns\BuildsTourFixtures;
use Tests\TestCase;

class CheckoutHttpTest extends TestCase
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

    private function booking(): TourBooking
    {
        return $this->persistedBooking($this->customer(), $this->bookableDeparture());
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'provider' => PaymentProvider::BankTransfer->value,
            'idempotency_key' => (string) Str::uuid(),
            'acknowledge_terms' => '1',
        ], $overrides);
    }

    public function test_the_owner_sees_the_amount_due_and_available_methods(): void
    {
        $booking = $this->booking();

        $this->actingAs($booking->customer)
            ->get(route('payments.checkout', ['tour-bookings', $booking->reference]))
            ->assertOk()
            ->assertSee($booking->reference)
            ->assertSee('Amount due now')
            ->assertSee('Bank transfer')
            ->assertSee('cannot be changed from this page');
    }

    public function test_a_guest_is_sent_to_login(): void
    {
        $booking = $this->booking();

        $this->get(route('payments.checkout', ['tour-bookings', $booking->reference]))
            ->assertRedirect(route('login'));
    }

    public function test_another_customer_cannot_open_the_checkout(): void
    {
        $booking = $this->booking();

        // Scoped to the signed-in customer, so a foreign reference is a 404
        // rather than a 403 that would confirm the booking exists.
        $this->actingAs($this->customer())
            ->get(route('payments.checkout', ['tour-bookings', $booking->reference]))
            ->assertNotFound();
    }

    public function test_an_unknown_payable_type_is_not_found(): void
    {
        $booking = $this->booking();

        // The type segment is an allowlist, not a resolved class name.
        $this->actingAs($booking->customer)
            ->get(route('payments.checkout', ['users', $booking->reference]))
            ->assertNotFound();
    }

    public function test_starting_a_checkout_creates_an_intent_and_lands_on_the_status_page(): void
    {
        $booking = $this->booking();

        $response = $this->actingAs($booking->customer)
            ->post(route('payments.checkout.store', ['tour-bookings', $booking->reference]), $this->payload());

        $payment = Payment::query()->sole();
        $this->assertSame($booking->total_minor, $payment->amount_minor);
        $this->assertSame($booking->customer_id, $payment->customer_id);
        $this->assertSame(PaymentStatus::Pending, $payment->status);

        $response->assertRedirect(route('payments.status', $payment));
    }

    public function test_the_status_page_shows_bank_instructions_and_the_exact_reference(): void
    {
        config([
            'payments.providers.bank_transfer.bank_name' => 'Stanbic Bank Uganda',
            'payments.providers.bank_transfer.account_name' => 'PISFA Tours and Travels',
            'payments.providers.bank_transfer.account_number' => '9030001234567',
        ]);
        $booking = $this->booking();

        $this->actingAs($booking->customer)
            ->post(route('payments.checkout.store', ['tour-bookings', $booking->reference]), $this->payload());

        $payment = Payment::query()->sole();

        $this->actingAs($booking->customer)
            ->get(route('payments.status', $payment))
            ->assertOk()
            ->assertSee('Stanbic Bank Uganda')
            ->assertSee('9030001234567')
            ->assertSee($payment->reference)
            ->assertSee('Quote the payment reference exactly');
    }

    public function test_checkout_requires_the_amount_acknowledgement(): void
    {
        $booking = $this->booking();
        $payload = $this->payload();
        unset($payload['acknowledge_terms']);

        $this->actingAs($booking->customer)
            ->post(route('payments.checkout.store', ['tour-bookings', $booking->reference]), $payload)
            ->assertSessionHasErrors('acknowledge_terms');

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_a_cancelled_booking_has_no_checkout(): void
    {
        $booking = $this->booking();
        $booking->forceFill(['status' => TourBookingStatus::Cancelled])->save();

        $this->actingAs($booking->customer)
            ->get(route('payments.checkout', ['tour-bookings', $booking->reference]))
            ->assertNotFound();
    }

    public function test_another_customer_cannot_view_a_payment_status_page(): void
    {
        $booking = $this->booking();
        $this->actingAs($booking->customer)
            ->post(route('payments.checkout.store', ['tour-bookings', $booking->reference]), $this->payload());
        $payment = Payment::query()->sole();

        $this->actingAs($this->customer())
            ->get(route('payments.status', $payment))
            ->assertForbidden();
    }

    public function test_operations_staff_may_view_a_payment_status_page(): void
    {
        $booking = $this->booking();
        $this->actingAs($booking->customer)
            ->post(route('payments.checkout.store', ['tour-bookings', $booking->reference]), $this->payload());
        $payment = Payment::query()->sole();

        $this->actingAs($this->user(UserRole::Staff, ['two_factor_required' => false]))
            ->get(route('payments.status', $payment))
            ->assertOk()
            ->assertSee($payment->reference);
    }

    public function test_a_disabled_provider_is_not_offered(): void
    {
        config(['payments.providers.bank_transfer.enabled' => false]);
        $booking = $this->booking();

        $this->actingAs($booking->customer)
            ->get(route('payments.checkout', ['tour-bookings', $booking->reference]))
            ->assertOk()
            ->assertSee('No payment method is available')
            ->assertDontSee('Continue to payment');
    }

    public function test_cash_is_never_offered_to_a_customer(): void
    {
        config(['payments.providers.cash.enabled' => true]);
        $booking = $this->booking();

        $this->actingAs($booking->customer)
            ->get(route('payments.checkout', ['tour-bookings', $booking->reference]))
            ->assertOk()
            ->assertDontSee('value="cash"', escape: false);
    }

    public function test_the_webhook_endpoint_rejects_an_unknown_provider(): void
    {
        $this->postJson(route('webhooks.payments', ['provider' => 'not-a-provider']), [])
            ->assertNotFound();
    }

    public function test_the_webhook_endpoint_rejects_a_manual_provider(): void
    {
        // Bank transfer and cash never send webhooks, so any request claiming
        // to be one is illegitimate by definition.
        $this->postJson(route('webhooks.payments', ['provider' => 'bank_transfer']), [])
            ->assertNotFound();
    }

    public function test_the_webhook_endpoint_is_reachable_without_a_session_or_csrf_token(): void
    {
        config(['payments.providers.flutterwave.enabled' => true]);
        $gateway = new FakeGateway(PaymentProvider::Flutterwave);
        $gateway->withInvalidSignature();
        app(PaymentGatewayRegistry::class)->register($gateway);

        // No session, no CSRF token: it reaches the controller and is rejected
        // on signature, which is the only thing protecting it.
        $this->postJson(route('webhooks.payments', ['provider' => 'flutterwave']), ['event_id' => 'x'])
            ->assertStatus(400)
            ->assertJsonPath('message', 'Invalid signature.');
    }
}
