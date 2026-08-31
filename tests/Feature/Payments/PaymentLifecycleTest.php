<?php

namespace Tests\Feature\Payments;

use App\Actions\Payments\CreatePaymentIntent;
use App\Actions\Payments\SettlePayment;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Enums\TourBookingEventType;
use App\Enums\TourBookingStatus;
use App\Models\Payment;
use App\Models\TourBooking;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Tours\Concerns\BuildsTourFixtures;
use Tests\TestCase;

class PaymentLifecycleTest extends TestCase
{
    use BuildsTourFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo('2026-08-20 09:00:00');
        Notification::fake();
    }

    private function payableBooking(): TourBooking
    {
        return $this->persistedBooking($this->customer(), $this->bookableDeparture());
    }

    public function test_an_intent_takes_its_amount_from_the_booking_not_the_caller(): void
    {
        $booking = $this->payableBooking();

        $payment = app(CreatePaymentIntent::class)->execute(
            $booking->customer,
            $booking,
            PaymentProvider::BankTransfer,
            (string) Str::uuid(),
        );

        $this->assertSame($booking->total_minor, $payment->amount_minor);
        $this->assertSame($booking->currency, $payment->currency);
        $this->assertSame(PaymentStatus::Pending, $payment->status);
        $this->assertSame($booking->customer_id, $payment->customer_id);
        $this->assertDatabaseHas('audit_logs', ['event' => 'payment.intent_created']);
    }

    public function test_repeating_an_idempotency_key_returns_the_same_intent(): void
    {
        $booking = $this->payableBooking();
        $key = (string) Str::uuid();
        $action = app(CreatePaymentIntent::class);

        $first = $action->execute($booking->customer, $booking, PaymentProvider::BankTransfer, $key);
        $second = $action->execute($booking->customer, $booking, PaymentProvider::BankTransfer, $key);

        $this->assertTrue($first->is($second));
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_a_second_intent_is_refused_while_one_is_still_in_flight(): void
    {
        $booking = $this->payableBooking();
        $action = app(CreatePaymentIntent::class);

        $first = $action->execute($booking->customer, $booking, PaymentProvider::BankTransfer, (string) Str::uuid());
        // A different key, but the same booking with an unexpired intent: the
        // customer opened a second tab. They must not be charged twice.
        $second = $action->execute($booking->customer, $booking, PaymentProvider::BankTransfer, (string) Str::uuid());

        $this->assertTrue($first->is($second));
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_a_customer_cannot_create_an_intent_for_another_customers_booking(): void
    {
        $booking = $this->payableBooking();
        $stranger = $this->customer();

        $this->expectException(AuthorizationException::class);

        app(CreatePaymentIntent::class)->execute(
            $stranger,
            $booking,
            PaymentProvider::BankTransfer,
            (string) Str::uuid(),
        );
    }

    public function test_a_customer_cannot_select_cash(): void
    {
        $booking = $this->payableBooking();

        // Cash represents money already handed over; a customer selecting it
        // would be recording a fiction.
        $this->expectException(AuthorizationException::class);

        app(CreatePaymentIntent::class)->execute(
            $booking->customer,
            $booking,
            PaymentProvider::Cash,
            (string) Str::uuid(),
        );
    }

    public function test_a_provider_that_cannot_settle_the_currency_is_refused(): void
    {
        config(['payments.providers.mtn_momo.enabled' => true]);
        $booking = $this->payableBooking();
        $booking->forceFill(['currency' => 'USD'])->save();

        // Ugandan mobile money is UGX only; offering USD guarantees failure.
        $this->expectException(ValidationException::class);

        app(CreatePaymentIntent::class)->execute(
            $booking->fresh()->customer,
            $booking->fresh(),
            PaymentProvider::MtnMobileMoney,
            (string) Str::uuid(),
        );
    }

    public function test_settlement_allocates_and_marks_the_booking_paid_in_full(): void
    {
        $booking = $this->payableBooking();
        $staff = $this->operationsUser();

        $payment = app(CreatePaymentIntent::class)->execute(
            $booking->customer,
            $booking,
            PaymentProvider::BankTransfer,
            (string) Str::uuid(),
        );

        app(SettlePayment::class)->execute(
            payment: $payment,
            actor: $staff,
            source: 'manual',
        );

        $payment->refresh();
        $this->assertSame(PaymentStatus::Paid, $payment->status);
        $this->assertNotNull($payment->paid_at);

        $this->assertDatabaseHas('payment_allocations', [
            'payment_id' => $payment->getKey(),
            'allocatable_type' => $booking->getMorphClass(),
            'allocatable_id' => $booking->getKey(),
            'amount_minor' => $booking->total_minor,
        ]);

        $this->assertSame(0, $booking->fresh()->outstandingAmountMinor());
        $this->assertTrue($booking->fresh()->isPaidInFull());
        $this->assertDatabaseHas('audit_logs', ['event' => 'payment.settled']);
    }

    public function test_settling_twice_is_a_no_op_and_awards_loyalty_once(): void
    {
        $booking = $this->payableBooking();
        $staff = $this->operationsUser();
        $payment = app(CreatePaymentIntent::class)->execute(
            $booking->customer,
            $booking,
            PaymentProvider::BankTransfer,
            (string) Str::uuid(),
        );

        $action = app(SettlePayment::class);
        $action->execute(payment: $payment, actor: $staff, source: 'manual');
        $action->execute(payment: $payment->fresh(), actor: $staff, source: 'manual');

        // One allocation, one loyalty marker, regardless of how many times a
        // provider retries or an operator clicks.
        $this->assertDatabaseCount('payment_allocations', 1);
        $this->assertSame(1, $booking->events()
            ->where('event_type', TourBookingEventType::LoyaltyEligible->value)
            ->count());
    }

    public function test_settlement_refuses_a_mismatched_amount(): void
    {
        $booking = $this->payableBooking();
        $payment = app(CreatePaymentIntent::class)->execute(
            $booking->customer,
            $booking,
            PaymentProvider::BankTransfer,
            (string) Str::uuid(),
        );

        $this->expectException(ValidationException::class);

        // A tampered or misrouted callback claiming a trivial amount must not
        // settle a full booking.
        app(SettlePayment::class)->execute(
            payment: $payment,
            verifiedAmountMinor: 100,
            verifiedCurrency: $payment->currency,
        );
    }

    public function test_settlement_refuses_a_mismatched_currency(): void
    {
        $booking = $this->payableBooking();
        $payment = app(CreatePaymentIntent::class)->execute(
            $booking->customer,
            $booking,
            PaymentProvider::BankTransfer,
            (string) Str::uuid(),
        );

        $this->expectException(ValidationException::class);

        app(SettlePayment::class)->execute(
            payment: $payment,
            verifiedAmountMinor: $payment->amount_minor,
            verifiedCurrency: 'USD',
        );
    }

    public function test_a_cancelled_booking_does_not_accept_payment(): void
    {
        $booking = $this->payableBooking();
        $booking->forceFill(['status' => TourBookingStatus::Cancelled])->save();

        $this->expectException(ValidationException::class);

        app(CreatePaymentIntent::class)->execute(
            $booking->fresh()->customer,
            $booking->fresh(),
            PaymentProvider::BankTransfer,
            (string) Str::uuid(),
        );
    }

    public function test_a_fully_paid_booking_cannot_be_paid_again(): void
    {
        $booking = $this->payableBooking();
        $staff = $this->operationsUser();
        $payment = app(CreatePaymentIntent::class)->execute(
            $booking->customer,
            $booking,
            PaymentProvider::BankTransfer,
            (string) Str::uuid(),
        );
        app(SettlePayment::class)->execute(payment: $payment, actor: $staff, source: 'manual');

        $this->expectException(ValidationException::class);

        app(CreatePaymentIntent::class)->execute(
            $booking->fresh()->customer,
            $booking->fresh(),
            PaymentProvider::BankTransfer,
            (string) Str::uuid(),
        );
    }

    public function test_a_pending_payment_never_reduces_the_outstanding_balance(): void
    {
        $booking = $this->payableBooking();

        app(CreatePaymentIntent::class)->execute(
            $booking->customer,
            $booking,
            PaymentProvider::BankTransfer,
            (string) Str::uuid(),
        );

        // The intent exists but no money has arrived.
        $this->assertSame($booking->total_minor, $booking->fresh()->outstandingAmountMinor());
        $this->assertFalse($booking->fresh()->isPaidInFull());
    }

    public function test_the_base_currency_amount_is_stamped_at_creation(): void
    {
        config(['payments.base_currency' => 'UGX', 'payments.exchange_rates.USD_UGX' => 3_800_000_000]);
        $booking = $this->payableBooking();
        $booking->forceFill(['currency' => 'USD', 'total_minor' => 10_000])->save();

        $payment = app(CreatePaymentIntent::class)->execute(
            $booking->fresh()->customer,
            $booking->fresh(),
            PaymentProvider::BankTransfer,
            (string) Str::uuid(),
        );

        // USD 100.00 at 3,800 UGX/USD = UGX 380,000 (exponent 2 -> 0).
        $this->assertSame('UGX', $payment->base_currency);
        $this->assertSame(380_000, $payment->base_amount_minor);
        $this->assertSame(3_800_000_000, $payment->exchange_rate_ppm);

        // A later rate change must not rewrite this payment's base amount.
        config(['payments.exchange_rates.USD_UGX' => 4_500_000_000]);
        $this->assertSame(380_000, $payment->fresh()->base_amount_minor);
    }

    public function test_an_unregistered_enabled_provider_fails_loudly(): void
    {
        config(['payments.providers.stripe.enabled' => true]);
        $booking = $this->payableBooking();

        // Better to fail at resolution than to silently accept money we cannot
        // actually collect.
        $this->expectException(ValidationException::class);

        app(CreatePaymentIntent::class)->execute(
            $booking->customer,
            $booking,
            PaymentProvider::Stripe,
            (string) Str::uuid(),
        );
    }
}
