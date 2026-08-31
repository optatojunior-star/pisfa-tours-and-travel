<?php

namespace Tests\Feature\Payments;

use App\Actions\Payments\ProcessPaymentWebhook;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Enums\TourBookingEventType;
use App\Models\Payment;
use App\Models\PaymentWebhookEvent;
use App\Models\TourBooking;
use App\Services\Payments\Gateways\FakeGateway;
use App\Services\Payments\PaymentGatewayRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Tours\Concerns\BuildsTourFixtures;
use Tests\TestCase;

class PaymentWebhookTest extends TestCase
{
    use BuildsTourFixtures;
    use RefreshDatabase;

    private FakeGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo('2026-08-20 09:00:00');
        Notification::fake();

        // No test touches a real provider or needs credentials.
        $this->gateway = new FakeGateway(PaymentProvider::Flutterwave);
        app(PaymentGatewayRegistry::class)->register($this->gateway);
        config(['payments.providers.flutterwave.enabled' => true]);
    }

    private function pendingPayment(): Payment
    {
        $booking = $this->persistedBooking($this->customer(), $this->bookableDeparture());

        return Payment::factory()
            ->forPayable($booking)
            ->usingProvider(PaymentProvider::Flutterwave)
            ->withStatus(PaymentStatus::Processing)
            ->create();
    }

    /** @param array<string, mixed> $body */
    private function deliver(array $body): array
    {
        $request = Request::create('/webhooks/payments/flutterwave', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode($body) ?: '');

        return app(ProcessPaymentWebhook::class)->execute(PaymentProvider::Flutterwave, $request);
    }

    public function test_a_successful_notification_settles_and_allocates(): void
    {
        $payment = $this->pendingPayment();

        $result = $this->deliver([
            'event_id' => 'evt_success_1',
            'type' => 'charge.completed',
            'reference' => $payment->reference,
            'transaction_id' => 'txn_1',
            'status' => PaymentStatus::Paid->value,
            'amount_minor' => $payment->amount_minor,
            'currency' => $payment->currency,
        ]);

        $this->assertTrue($result['handled']);
        $payment->refresh();
        $this->assertSame(PaymentStatus::Paid, $payment->status);
        $this->assertSame('txn_1', $payment->provider_transaction_id);
        $this->assertDatabaseHas('payment_allocations', ['payment_id' => $payment->getKey()]);
        $this->assertDatabaseHas('payment_webhook_events', [
            'event_id' => 'evt_success_1',
            'signature_verified' => true,
        ]);
    }

    public function test_an_invalid_signature_is_rejected_before_parsing(): void
    {
        $payment = $this->pendingPayment();
        $this->gateway->withInvalidSignature();

        $result = $this->deliver([
            'event_id' => 'evt_forged',
            'reference' => $payment->reference,
            'status' => PaymentStatus::Paid->value,
            'amount_minor' => $payment->amount_minor,
            'currency' => $payment->currency,
        ]);

        $this->assertFalse($result['handled']);
        $this->assertSame('invalid_signature', $result['reason']);
        $this->assertSame(PaymentStatus::Processing, $payment->fresh()->status);

        // Nothing is stored for an unverified payload, but the rejection is audited.
        $this->assertDatabaseCount('payment_webhook_events', 0);
        $this->assertDatabaseHas('audit_logs', ['event' => 'payment.webhook_rejected']);
    }

    public function test_a_duplicate_delivery_is_acknowledged_without_reprocessing(): void
    {
        $payment = $this->pendingPayment();
        $body = [
            'event_id' => 'evt_dup',
            'reference' => $payment->reference,
            'transaction_id' => 'txn_dup',
            'status' => PaymentStatus::Paid->value,
            'amount_minor' => $payment->amount_minor,
            'currency' => $payment->currency,
        ];

        $first = $this->deliver($body);
        $second = $this->deliver($body);
        $third = $this->deliver($body);

        $this->assertTrue($first['handled']);
        $this->assertTrue($second['duplicate']);
        $this->assertTrue($third['duplicate']);

        // One event row, one allocation, one loyalty marker.
        $this->assertDatabaseCount('payment_webhook_events', 1);
        $this->assertDatabaseCount('payment_allocations', 1);

        $booking = $payment->fresh()->payable;
        $this->assertInstanceOf(TourBooking::class, $booking);
        $this->assertSame(1, $booking->events()
            ->where('event_type', TourBookingEventType::LoyaltyEligible->value)
            ->count());
    }

    public function test_a_failure_notification_marks_the_payment_failed(): void
    {
        $payment = $this->pendingPayment();

        $result = $this->deliver([
            'event_id' => 'evt_fail',
            'reference' => $payment->reference,
            'status' => PaymentStatus::Failed->value,
            'failure_reason' => 'Insufficient funds.',
        ]);

        $this->assertTrue($result['handled']);
        $payment->refresh();
        $this->assertSame(PaymentStatus::Failed, $payment->status);
        $this->assertSame('Insufficient funds.', $payment->failure_reason);
        $this->assertNotNull($payment->failed_at);
        $this->assertDatabaseCount('payment_allocations', 0);
    }

    public function test_a_cancellation_notification_marks_the_payment_cancelled(): void
    {
        $payment = $this->pendingPayment();

        $this->deliver([
            'event_id' => 'evt_cancel',
            'reference' => $payment->reference,
            'status' => PaymentStatus::Cancelled->value,
        ]);

        $payment->refresh();
        $this->assertSame(PaymentStatus::Cancelled, $payment->status);
        $this->assertNotNull($payment->cancelled_at);
        $this->assertTrue($payment->status->allowsRetry());
    }

    public function test_a_pending_notification_advances_without_settling(): void
    {
        $payment = Payment::factory()
            ->forPayable($this->persistedBooking($this->customer(), $this->bookableDeparture()))
            ->usingProvider(PaymentProvider::Flutterwave)
            ->withStatus(PaymentStatus::Pending)
            ->create();

        $this->deliver([
            'event_id' => 'evt_pending',
            'reference' => $payment->reference,
            'transaction_id' => 'txn_pending',
            'status' => PaymentStatus::RequiresAction->value,
        ]);

        $payment->refresh();
        $this->assertSame(PaymentStatus::RequiresAction, $payment->status);
        $this->assertSame('txn_pending', $payment->provider_transaction_id);
        $this->assertDatabaseCount('payment_allocations', 0);
    }

    public function test_a_late_failure_never_downgrades_a_settled_payment(): void
    {
        $payment = $this->pendingPayment();

        $this->deliver([
            'event_id' => 'evt_paid',
            'reference' => $payment->reference,
            'status' => PaymentStatus::Paid->value,
            'amount_minor' => $payment->amount_minor,
            'currency' => $payment->currency,
        ]);

        // A stale failure arriving after settlement must not unsettle real money.
        $this->deliver([
            'event_id' => 'evt_late_fail',
            'reference' => $payment->reference,
            'status' => PaymentStatus::Failed->value,
            'failure_reason' => 'Late duplicate notice.',
        ]);

        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
    }

    public function test_a_notification_with_the_wrong_amount_does_not_settle(): void
    {
        $payment = $this->pendingPayment();

        $result = $this->deliver([
            'event_id' => 'evt_tampered',
            'reference' => $payment->reference,
            'status' => PaymentStatus::Paid->value,
            'amount_minor' => 100,
            'currency' => $payment->currency,
        ]);

        $this->assertFalse($result['handled']);
        $this->assertSame('processing_failed', $result['reason']);
        $this->assertSame(PaymentStatus::Processing, $payment->fresh()->status);

        // The delivery is retained with its error so an operator can see it.
        $event = PaymentWebhookEvent::query()->where('event_id', 'evt_tampered')->sole();
        $this->assertNotNull($event->processing_error);
        $this->assertNull($event->processed_at);
    }

    public function test_an_event_for_an_unknown_payment_changes_nothing(): void
    {
        $result = $this->deliver([
            'event_id' => 'evt_orphan',
            'reference' => 'PAY-DOESNOTEXIST',
            'status' => PaymentStatus::Paid->value,
        ]);

        $this->assertFalse($result['handled']);
        $this->assertDatabaseCount('payment_allocations', 0);
        // Still logged, so an unexplained provider notification is visible.
        $this->assertDatabaseCount('payment_webhook_events', 1);
    }

    public function test_an_unparseable_payload_is_acknowledged_but_ignored(): void
    {
        $result = $this->deliver(['nothing' => 'useful']);

        $this->assertFalse($result['handled']);
        $this->assertSame('ignored', $result['reason']);
        $this->assertDatabaseCount('payment_webhook_events', 0);
    }

    public function test_sensitive_fields_are_redacted_before_the_payload_is_stored(): void
    {
        $payment = $this->pendingPayment();

        $this->deliver([
            'event_id' => 'evt_pii',
            'reference' => $payment->reference,
            'status' => PaymentStatus::Paid->value,
            'amount_minor' => $payment->amount_minor,
            'currency' => $payment->currency,
            'card_number' => '4111111111111111',
            'phone' => '+256700000000',
            'customer' => ['email' => 'someone@example.test', 'token' => 'tok_live_secret'],
        ]);

        $stored = PaymentWebhookEvent::query()->where('event_id', 'evt_pii')->sole();
        $encoded = json_encode($stored->payload);

        $this->assertStringNotContainsString('4111111111111111', (string) $encoded);
        $this->assertStringNotContainsString('tok_live_secret', (string) $encoded);
        $this->assertStringNotContainsString('someone@example.test', (string) $encoded);
        $this->assertStringNotContainsString('+256700000000', (string) $encoded);
        $this->assertStringContainsString('[REDACTED]', (string) $encoded);
    }

    public function test_an_event_from_the_wrong_provider_is_refused(): void
    {
        $booking = $this->persistedBooking($this->customer(), $this->bookableDeparture());
        // The payment was created against a different provider entirely.
        $payment = Payment::factory()
            ->forPayable($booking)
            ->usingProvider(PaymentProvider::PesaPal)
            ->withStatus(PaymentStatus::Processing)
            ->create();

        $result = $this->deliver([
            'event_id' => 'evt_wrong_provider',
            'reference' => $payment->reference,
            'status' => PaymentStatus::Paid->value,
            'amount_minor' => $payment->amount_minor,
            'currency' => $payment->currency,
        ]);

        $this->assertFalse($result['handled']);
        $this->assertSame(PaymentStatus::Processing, $payment->fresh()->status);
    }
}
