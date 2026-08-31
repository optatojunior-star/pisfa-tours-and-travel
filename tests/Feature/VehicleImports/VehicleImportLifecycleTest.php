<?php

namespace Tests\Feature\VehicleImports;

use App\Actions\Payments\SettlePayment;
use App\Actions\VehicleImports\QuoteVehicleImportOrder;
use App\Actions\VehicleImports\TransitionVehicleImportOrder;
use App\Enums\AccountStatus;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Enums\VehicleImportEventType;
use App\Enums\VehicleImportStatus;
use App\Models\Payment;
use App\Models\User;
use App\Models\VehicleImportOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class VehicleImportLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo('2026-08-20 09:00:00');
        Notification::fake();
    }

    private function staff(): User
    {
        return User::factory()->create([
            'role' => UserRole::Staff,
            'status' => AccountStatus::Active,
            'email_verified_at' => now(),
            'two_factor_required' => false,
        ]);
    }

    private function customer(): User
    {
        return User::factory()->create([
            'role' => UserRole::Customer,
            'status' => AccountStatus::Active,
            'email_verified_at' => now(),
            'phone' => '+256700111222',
        ]);
    }

    /** Settle a payment of the currently-due amount against the order. */
    private function pay(VehicleImportOrder $order, User $actor): Payment
    {
        $payment = Payment::factory()->create([
            'payable_type' => $order->getMorphClass(),
            'payable_id' => $order->getKey(),
            'customer_id' => $order->customer_id,
            'provider' => PaymentProvider::BankTransfer,
            'status' => PaymentStatus::Pending,
            'amount_minor' => $order->outstandingAmountMinor(),
            'base_amount_minor' => $order->outstandingAmountMinor(),
            'currency' => $order->payableCurrency(),
            'base_currency' => $order->payableCurrency(),
        ]);

        app(SettlePayment::class)->execute(payment: $payment, actor: $actor, source: 'manual');

        return $payment->fresh();
    }

    public function test_the_full_lifecycle_runs_from_inquiry_to_delivered(): void
    {
        $staff = $this->staff();
        $customer = $this->customer();
        $order = VehicleImportOrder::factory()->forCustomer($customer)->create();
        $transition = app(TransitionVehicleImportOrder::class);

        $transition->execute($staff, $order, VehicleImportStatus::Reviewing);

        app(QuoteVehicleImportOrder::class)->execute($staff, $order->fresh(), [
            'total_price' => '120000000',
            'deposit' => '30000000',
            'currency' => 'UGX',
            'estimated_arrival_on' => now()->addDays(60)->toDateString(),
        ]);

        $order->refresh();
        $this->assertTrue($order->hasQuote());
        $this->assertSame(30_000_000, $order->outstandingAmountMinor(), 'Only the deposit is due first.');

        $transition->execute($staff, $order, VehicleImportStatus::Quoted);

        // Deposit
        $this->pay($order->fresh(), $staff);
        $order->refresh();
        $this->assertTrue($order->depositIsSettled());
        $transition->execute($staff, $order, VehicleImportStatus::DepositPaid);

        foreach ([
            VehicleImportStatus::CarLocated,
            VehicleImportStatus::Shipping,
            VehicleImportStatus::PortClearance,
            VehicleImportStatus::InTransit,
            VehicleImportStatus::ReadyForDelivery,
        ] as $next) {
            $transition->execute($staff, $order->fresh(), $next);
        }

        $order->refresh();
        $this->assertSame(90_000_000, $order->outstandingAmountMinor(), 'The balance is due once ready.');

        // Balance
        $this->pay($order, $staff);
        $order->refresh();
        $this->assertSame(0, $order->outstandingAmountMinor());

        $transition->execute($staff, $order, VehicleImportStatus::Delivered);

        $order->refresh();
        $this->assertSame(VehicleImportStatus::Delivered, $order->status);
        $this->assertNotNull($order->delivered_at);
        $this->assertSame(1, $order->events()
            ->where('event_type', VehicleImportEventType::LoyaltyEligible->value)
            ->count());
    }

    public function test_only_the_deposit_is_collectable_before_it_is_paid(): void
    {
        $order = VehicleImportOrder::factory()->quoted(120_000_000, 30_000_000)->create();

        // Not the full price — asking for that up front would be wrong.
        $this->assertSame(30_000_000, $order->outstandingAmountMinor());
        $this->assertSame('Deposit', $order->nextPaymentLabel());
        $this->assertTrue($order->acceptsPayment());
    }

    public function test_the_balance_is_not_collectable_until_the_vehicle_is_ready(): void
    {
        $staff = $this->staff();
        $order = VehicleImportOrder::factory()->quoted(120_000_000, 30_000_000)->create();
        $this->pay($order, $staff);

        // Deposit settled, but the vehicle is still being sourced.
        $order->refresh();
        $this->assertTrue($order->depositIsSettled());
        $this->assertSame(0, $order->outstandingAmountMinor());
        $this->assertFalse($order->acceptsPayment());

        $order->forceFill(['status' => VehicleImportStatus::ReadyForDelivery])->save();
        $this->assertSame(90_000_000, $order->fresh()->outstandingAmountMinor());
        $this->assertSame('Balance', $order->fresh()->nextPaymentLabel());
    }

    public function test_deposit_paid_is_refused_until_the_money_actually_arrives(): void
    {
        $staff = $this->staff();
        $order = VehicleImportOrder::factory()->quoted()->create();

        // Sourcing costs PISFA money, so an operator cannot simply declare it.
        $this->expectException(ValidationException::class);

        app(TransitionVehicleImportOrder::class)
            ->execute($staff, $order, VehicleImportStatus::DepositPaid);
    }

    public function test_delivery_is_refused_until_the_balance_is_settled(): void
    {
        $staff = $this->staff();
        $order = VehicleImportOrder::factory()
            ->quoted(120_000_000, 30_000_000)
            ->withStatus(VehicleImportStatus::ReadyForDelivery)
            ->create();
        $this->pay($order, $staff);

        $this->expectException(ValidationException::class);

        app(TransitionVehicleImportOrder::class)
            ->execute($staff, $order->fresh(), VehicleImportStatus::Delivered);
    }

    public function test_an_invalid_status_jump_is_rejected(): void
    {
        $staff = $this->staff();
        $order = VehicleImportOrder::factory()->create();

        $this->expectException(ValidationException::class);

        app(TransitionVehicleImportOrder::class)
            ->execute($staff, $order, VehicleImportStatus::Shipping);
    }

    public function test_cancelling_requires_a_reason(): void
    {
        $staff = $this->staff();
        $order = VehicleImportOrder::factory()->create();

        $this->expectException(ValidationException::class);

        app(TransitionVehicleImportOrder::class)
            ->execute($staff, $order, VehicleImportStatus::Cancelled);
    }

    public function test_a_quote_cannot_be_revised_once_the_deposit_is_paid(): void
    {
        $staff = $this->staff();
        $order = VehicleImportOrder::factory()->quoted(120_000_000, 30_000_000)->create();
        $this->pay($order, $staff);

        // Re-quoting would change what the customer already paid against.
        $this->expectException(ValidationException::class);

        app(QuoteVehicleImportOrder::class)->execute($staff, $order->fresh(), [
            'total_price' => '150000000',
            'deposit' => '40000000',
            'currency' => 'UGX',
            'estimated_arrival_on' => now()->addDays(60)->toDateString(),
        ]);
    }

    public function test_a_quote_may_be_revised_while_it_is_unpaid(): void
    {
        $staff = $this->staff();
        $order = VehicleImportOrder::factory()->quoted(120_000_000, 30_000_000)->create();

        app(QuoteVehicleImportOrder::class)->execute($staff, $order, [
            'total_price' => '99000000',
            'deposit' => '25000000',
            'currency' => 'UGX',
            'estimated_arrival_on' => now()->addDays(45)->toDateString(),
        ]);

        $order->refresh();
        $this->assertSame(99_000_000, $order->total_price_minor);
        $this->assertSame(25_000_000, $order->outstandingAmountMinor());
    }

    public function test_a_deposit_larger_than_the_total_is_refused(): void
    {
        $staff = $this->staff();
        $order = VehicleImportOrder::factory()->create();

        $this->expectException(ValidationException::class);

        app(QuoteVehicleImportOrder::class)->execute($staff, $order, [
            'total_price' => '50000000',
            'deposit' => '60000000',
            'currency' => 'UGX',
            'estimated_arrival_on' => now()->addDays(30)->toDateString(),
        ]);
    }

    public function test_an_expired_quotation_stops_accepting_payment(): void
    {
        $order = VehicleImportOrder::factory()->expiredQuote()->create();

        $this->assertTrue($order->quoteHasExpired());
        $this->assertFalse($order->acceptsPayment());
    }

    public function test_a_cancelled_import_never_accepts_payment(): void
    {
        $order = VehicleImportOrder::factory()
            ->quoted()
            ->withStatus(VehicleImportStatus::Cancelled)
            ->create();

        $this->assertFalse($order->acceptsPayment());
        $this->assertSame(0, $order->outstandingAmountMinor());
    }

    public function test_an_unquoted_import_has_nothing_to_pay(): void
    {
        $order = VehicleImportOrder::factory()->create();

        $this->assertFalse($order->hasQuote());
        $this->assertSame(0, $order->outstandingAmountMinor());
        $this->assertFalse($order->acceptsPayment());
        $this->assertSame('Awaiting quotation', $order->nextPaymentLabel());
    }

    public function test_moving_to_quoted_requires_a_published_quotation(): void
    {
        $staff = $this->staff();
        $order = VehicleImportOrder::factory()->withStatus(VehicleImportStatus::Reviewing)->create();

        $this->expectException(ValidationException::class);

        app(TransitionVehicleImportOrder::class)
            ->execute($staff, $order, VehicleImportStatus::Quoted);
    }
}
