<?php

namespace Tests\Feature\Payments;

use App\Actions\Payments\RefundPayment;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Enums\UserRole;
use App\Models\Payment;
use App\Models\User;
use App\Services\Payments\Gateways\FakeGateway;
use App\Services\Payments\PaymentGatewayRegistry;
use App\Support\Payments\RefundResult;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Tours\Concerns\BuildsTourFixtures;
use Tests\TestCase;

class RefundTest extends TestCase
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

        $this->gateway = new FakeGateway(PaymentProvider::Flutterwave);
        app(PaymentGatewayRegistry::class)->register($this->gateway);
        config(['payments.providers.flutterwave.enabled' => true]);
    }

    private function settledPayment(int $amountMinor = 1_000_000): Payment
    {
        $booking = $this->persistedBooking($this->customer(), $this->bookableDeparture());

        return Payment::factory()
            ->forPayable($booking)
            ->usingProvider(PaymentProvider::Flutterwave)
            ->settled()
            ->create(['amount_minor' => $amountMinor, 'base_amount_minor' => $amountMinor]);
    }

    private function manager(): User
    {
        return $this->user(UserRole::Manager, ['two_factor_required' => false]);
    }

    public function test_a_manager_issues_a_full_refund(): void
    {
        $payment = $this->settledPayment();
        $manager = $this->manager();

        $refund = app(RefundPayment::class)->execute(
            $manager,
            $payment,
            $payment->amount_minor,
            'Trip cancelled by PISFA.',
            (string) Str::uuid(),
        );

        $this->assertSame(RefundStatus::Completed, $refund->status);
        $payment->refresh();
        $this->assertSame(PaymentStatus::Refunded, $payment->status);
        $this->assertSame($payment->amount_minor, $payment->refunded_amount_minor);
        $this->assertSame(0, $payment->netAmountMinor());
        $this->assertDatabaseHas('audit_logs', ['event' => 'payment.refund_requested']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'payment.refund_completed']);
    }

    public function test_a_partial_refund_leaves_the_payment_partially_refunded(): void
    {
        $payment = $this->settledPayment(1_000_000);
        $manager = $this->manager();

        app(RefundPayment::class)->execute(
            $manager,
            $payment,
            250_000,
            'Two travellers withdrew.',
            (string) Str::uuid(),
        );

        $payment->refresh();
        $this->assertSame(PaymentStatus::PartiallyRefunded, $payment->status);
        $this->assertSame(250_000, $payment->refunded_amount_minor);
        $this->assertSame(750_000, $payment->netAmountMinor());
        $this->assertSame(750_000, $payment->refundableAmountMinor());
    }

    public function test_successive_partial_refunds_cannot_exceed_the_collected_amount(): void
    {
        $payment = $this->settledPayment(1_000_000);
        $manager = $this->manager();
        $action = app(RefundPayment::class);

        $action->execute($manager, $payment, 600_000, 'First partial refund.', (string) Str::uuid());
        $action->execute($manager, $payment->fresh(), 400_000, 'Second partial refund.', (string) Str::uuid());

        $payment->refresh();
        $this->assertSame(PaymentStatus::Refunded, $payment->status);
        $this->assertSame(1_000_000, $payment->refunded_amount_minor);

        // A third attempt has no headroom left.
        $this->expectException(ValidationException::class);
        $action->execute($manager, $payment->fresh(), 1, 'One shilling too many.', (string) Str::uuid());
    }

    public function test_a_refund_larger_than_the_payment_is_refused(): void
    {
        $payment = $this->settledPayment(500_000);
        $manager = $this->manager();

        $this->expectException(ValidationException::class);

        app(RefundPayment::class)->execute(
            $manager,
            $payment,
            500_001,
            'Attempting to over-refund.',
            (string) Str::uuid(),
        );
    }

    public function test_repeating_a_refund_idempotency_key_returns_the_same_refund(): void
    {
        $payment = $this->settledPayment();
        $manager = $this->manager();
        $key = (string) Str::uuid();
        $action = app(RefundPayment::class);

        $first = $action->execute($manager, $payment, 100_000, 'Duplicate submit.', $key);
        $second = $action->execute($manager, $payment->fresh(), 100_000, 'Duplicate submit.', $key);

        $this->assertTrue($first->is($second));
        $this->assertDatabaseCount('refunds', 1);
        $this->assertSame(100_000, $payment->fresh()->refunded_amount_minor);
    }

    public function test_staff_and_customers_cannot_refund(): void
    {
        $payment = $this->settledPayment();

        foreach ([UserRole::Staff, UserRole::Customer, UserRole::Driver] as $role) {
            try {
                app(RefundPayment::class)->execute(
                    $this->user($role, ['two_factor_required' => false]),
                    $payment,
                    1000,
                    'Should not be permitted.',
                    (string) Str::uuid(),
                );
                $this->fail($role->value.' must not be able to refund.');
            } catch (AuthorizationException) {
                // expected — refunds are a finance action
            }
        }

        $this->assertDatabaseCount('refunds', 0);
    }

    public function test_a_super_administrator_may_refund(): void
    {
        $payment = $this->settledPayment();
        $admin = $this->user(UserRole::SuperAdmin, ['two_factor_required' => false]);

        $refund = app(RefundPayment::class)->execute(
            $admin,
            $payment,
            50_000,
            'Goodwill gesture approved.',
            (string) Str::uuid(),
        );

        $this->assertSame(RefundStatus::Completed, $refund->status);
    }

    public function test_an_unsettled_payment_cannot_be_refunded(): void
    {
        $booking = $this->persistedBooking($this->customer(), $this->bookableDeparture());
        $pending = Payment::factory()
            ->forPayable($booking)
            ->usingProvider(PaymentProvider::Flutterwave)
            ->withStatus(PaymentStatus::Pending)
            ->create();

        $this->expectException(ValidationException::class);

        app(RefundPayment::class)->execute(
            $this->manager(),
            $pending,
            1000,
            'Money never arrived.',
            (string) Str::uuid(),
        );
    }

    public function test_a_failed_provider_refund_does_not_reduce_the_settled_amount(): void
    {
        $payment = $this->settledPayment(1_000_000);
        $manager = $this->manager();
        $this->gateway->willRefund(RefundResult::failed('Provider declined the refund.'));

        $refund = app(RefundPayment::class)->execute(
            $manager,
            $payment,
            300_000,
            'Attempted refund that the provider rejects.',
            (string) Str::uuid(),
        );

        $this->assertSame(RefundStatus::Failed, $refund->status);
        $this->assertSame('Provider declined the refund.', $refund->failure_reason);

        $payment->refresh();
        // Nothing left the account, so revenue is untouched.
        $this->assertSame(PaymentStatus::Paid, $payment->status);
        $this->assertSame(0, $payment->refunded_amount_minor);
        // A failed refund releases its claim, so the full amount is available again.
        $this->assertSame(1_000_000, $payment->refundableAmountMinor());
    }

    public function test_an_in_flight_refund_holds_its_claim_on_the_headroom(): void
    {
        $payment = $this->settledPayment(1_000_000);
        $manager = $this->manager();
        $this->gateway->willRefund(RefundResult::processing('rfnd_async'));

        app(RefundPayment::class)->execute(
            $manager,
            $payment,
            400_000,
            'Async provider refund.',
            (string) Str::uuid(),
        );

        $payment->refresh();
        // Not yet completed, so revenue is unchanged...
        $this->assertSame(PaymentStatus::Paid, $payment->status);
        $this->assertSame(0, $payment->refunded_amount_minor);
        // ...but the amount is claimed, so it cannot be refunded twice.
        $this->assertSame(600_000, $payment->refundableAmountMinor());
    }

    public function test_a_manual_provider_refund_awaits_disbursement(): void
    {
        $booking = $this->persistedBooking($this->customer(), $this->bookableDeparture());
        $payment = Payment::factory()
            ->forPayable($booking)
            ->usingProvider(PaymentProvider::BankTransfer)
            ->settled()
            ->create(['amount_minor' => 200_000, 'base_amount_minor' => 200_000]);

        $refund = app(RefundPayment::class)->execute(
            $this->manager(),
            $payment,
            200_000,
            'Bank transfer to be reversed by finance.',
            (string) Str::uuid(),
        );

        // Money moves by hand, so it stays Processing until finance confirms.
        $this->assertSame(RefundStatus::Processing, $refund->status);
        $this->assertTrue((bool) ($refund->metadata['requires_manual_disbursement'] ?? false));
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
    }

    public function test_a_short_reason_is_rejected(): void
    {
        $payment = $this->settledPayment();

        $this->expectException(ValidationException::class);

        app(RefundPayment::class)->execute(
            $this->manager(),
            $payment,
            1000,
            'no',
            (string) Str::uuid(),
        );
    }
}
