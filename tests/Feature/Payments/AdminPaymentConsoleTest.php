<?php

namespace Tests\Feature\Payments;

use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Enums\UserRole;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Feature\Tours\Concerns\BuildsTourFixtures;
use Tests\TestCase;

class AdminPaymentConsoleTest extends TestCase
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

        // Manager and super administrator sit under the mandatory-2FA policy,
        // which would redirect before the refund flow is reached. That gate is
        // asserted on its own below; relaxing it here keeps these tests about
        // the money path rather than re-testing the middleware.
        config(['security.two_factor.required_roles' => []]);
    }

    private function manager(): User
    {
        return $this->user(UserRole::Manager, ['two_factor_required' => false]);
    }

    private function payment(array $overrides = []): Payment
    {
        $booking = $this->persistedBooking($this->customer(), $this->bookableDeparture());

        return Payment::factory()
            ->forPayable($booking)
            ->usingProvider(PaymentProvider::BankTransfer)
            ->create($overrides);
    }

    public function test_staff_can_search_and_filter_transactions(): void
    {
        $staff = $this->operationsUser();
        $pending = $this->payment();
        $settled = $this->payment(['status' => PaymentStatus::Paid, 'paid_at' => now()]);

        $this->actingAs($staff)
            ->get(route('admin.payments.index'))
            ->assertOk()
            ->assertSee($pending->reference)
            ->assertSee($settled->reference);

        $this->actingAs($staff)
            ->get(route('admin.payments.index', ['bucket' => 'in_flight']))
            ->assertOk()
            ->assertSee($pending->reference)
            ->assertDontSee($settled->reference);

        $this->actingAs($staff)
            ->get(route('admin.payments.index', ['q' => $pending->reference]))
            ->assertOk()
            ->assertSee($pending->reference)
            ->assertDontSee($settled->reference);
    }

    public function test_the_unreconciled_bucket_finds_settled_payments_with_no_allocation(): void
    {
        $staff = $this->operationsUser();
        // Settled directly in the factory, so no allocation was ever written.
        $orphan = $this->payment(['status' => PaymentStatus::Paid, 'paid_at' => now()]);

        $this->actingAs($staff)
            ->get(route('admin.payments.index', ['bucket' => 'unreconciled']))
            ->assertOk()
            ->assertSee($orphan->reference)
            ->assertSee('Unreconciled');
    }

    public function test_the_detail_screen_warns_when_a_settled_payment_is_uncredited(): void
    {
        $staff = $this->operationsUser();
        $orphan = $this->payment(['status' => PaymentStatus::Paid, 'paid_at' => now()]);

        $this->actingAs($staff)
            ->get(route('admin.payments.show', $orphan))
            ->assertOk()
            ->assertSee('has not been credited to any service');
    }

    public function test_recording_a_manual_receipt_settles_and_allocates(): void
    {
        $staff = $this->operationsUser();
        $payment = $this->payment();

        $this->actingAs($staff)
            ->post(route('admin.payments.record', $payment), [
                'evidence_reference' => 'SLIP-99321',
                'confirm_received' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $payment->refresh();
        $this->assertSame(PaymentStatus::Paid, $payment->status);
        $this->assertSame('SLIP-99321', $payment->provider_transaction_id);
        $this->assertDatabaseHas('payment_allocations', ['payment_id' => $payment->getKey()]);
    }

    public function test_recording_a_receipt_requires_evidence_and_confirmation(): void
    {
        $staff = $this->operationsUser();
        $payment = $this->payment();

        $this->actingAs($staff)
            ->from(route('admin.payments.show', $payment))
            ->post(route('admin.payments.record', $payment), ['confirm_received' => '1'])
            ->assertSessionHasErrors('evidence_reference');

        $this->actingAs($staff)
            ->from(route('admin.payments.show', $payment))
            ->post(route('admin.payments.record', $payment), ['evidence_reference' => 'SLIP-1'])
            ->assertSessionHasErrors('confirm_received');

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
    }

    public function test_a_remote_provider_payment_cannot_be_recorded_manually(): void
    {
        config(['payments.providers.flutterwave.enabled' => true]);
        $staff = $this->operationsUser();
        $payment = $this->payment(['provider' => PaymentProvider::Flutterwave]);

        // Only bank transfer and cash settle by hand; a card payment must come
        // from the provider or not at all.
        $this->actingAs($staff)
            ->post(route('admin.payments.record', $payment), [
                'evidence_reference' => 'FORGED',
                'confirm_received' => '1',
            ])
            ->assertNotFound();

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
    }

    public function test_a_manager_refunds_through_the_console(): void
    {
        $manager = $this->manager();
        $payment = $this->payment([
            'status' => PaymentStatus::Paid,
            'paid_at' => now(),
            'amount_minor' => 500_000,
        ]);

        $this->actingAs($manager)
            ->post(route('admin.payments.refund', $payment), [
                'amount' => '200000',
                'reason' => 'Partial cancellation agreed with the customer.',
                'idempotency_key' => (string) Str::uuid(),
                'confirm_refund' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $refund = Refund::query()->where('payment_id', $payment->getKey())->sole();
        $this->assertSame(200_000, $refund->amount_minor);

        // Bank transfer is manual: the money has not left yet, so the refund
        // sits in Processing and revenue is deliberately untouched until
        // finance confirms disbursement.
        $this->assertSame(RefundStatus::Processing, $refund->status);

        $payment->refresh();
        $this->assertSame(PaymentStatus::Paid, $payment->status);
        $this->assertSame(0, $payment->refunded_amount_minor);

        // The amount is still claimed, so it cannot be refunded a second time.
        $this->assertSame(300_000, $payment->refundableAmountMinor());
    }

    public function test_staff_cannot_reach_the_refund_form_or_endpoint(): void
    {
        $staff = $this->operationsUser();
        $payment = $this->payment(['status' => PaymentStatus::Paid, 'paid_at' => now()]);

        $this->actingAs($staff)
            ->get(route('admin.payments.show', $payment))
            ->assertOk()
            ->assertSee('restricted to managers and super administrators')
            ->assertDontSee('Issue refund');

        $this->actingAs($staff)
            ->post(route('admin.payments.refund', $payment), [
                'amount' => '1000',
                'reason' => 'Trying to refund without authority.',
                'idempotency_key' => (string) Str::uuid(),
                'confirm_refund' => '1',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('refunds', 0);
    }

    public function test_a_refund_over_the_available_balance_is_rejected(): void
    {
        $manager = $this->manager();
        $payment = $this->payment([
            'status' => PaymentStatus::Paid,
            'paid_at' => now(),
            'amount_minor' => 100_000,
        ]);

        $this->actingAs($manager)
            ->from(route('admin.payments.show', $payment))
            ->post(route('admin.payments.refund', $payment), [
                'amount' => '100001',
                'reason' => 'Attempting to over-refund.',
                'idempotency_key' => (string) Str::uuid(),
                'confirm_refund' => '1',
            ])
            ->assertSessionHasErrors('amount_minor');

        $this->assertSame(0, $payment->fresh()->refunded_amount_minor);
    }

    public function test_a_zero_refund_is_rejected(): void
    {
        $manager = $this->manager();
        $payment = $this->payment(['status' => PaymentStatus::Paid, 'paid_at' => now()]);

        $this->actingAs($manager)
            ->from(route('admin.payments.show', $payment))
            ->post(route('admin.payments.refund', $payment), [
                'amount' => '0',
                'reason' => 'Zero is not a refund.',
                'idempotency_key' => (string) Str::uuid(),
                'confirm_refund' => '1',
            ])
            ->assertSessionHasErrors('amount');

        $this->assertDatabaseCount('refunds', 0);
    }

    public function test_customers_and_drivers_are_denied_the_console(): void
    {
        $payment = $this->payment();

        foreach ([$this->customer(), $this->user(UserRole::Driver)] as $actor) {
            $this->actingAs($actor)->get(route('admin.payments.index'))->assertForbidden();
            $this->actingAs($actor)->get(route('admin.payments.show', $payment))->assertForbidden();
        }
    }

    public function test_a_guest_is_sent_to_login(): void
    {
        $this->get(route('admin.payments.index'))->assertRedirect(route('login'));
    }

    public function test_reconciliation_totals_convert_mixed_currencies_to_the_base(): void
    {
        config([
            'payments.base_currency' => 'UGX',
            'payments.exchange_rates.USD_UGX' => 3_800_000_000,
        ]);
        $staff = $this->operationsUser();

        // UGX 400,000 collected.
        $this->payment([
            'status' => PaymentStatus::Paid,
            'paid_at' => now(),
            'amount_minor' => 400_000,
            'currency' => 'UGX',
            'base_amount_minor' => 400_000,
            'base_currency' => 'UGX',
            'exchange_rate_ppm' => 1_000_000,
        ]);

        // USD 100.00 collected, stamped at 3,800 = UGX 380,000.
        $this->payment([
            'status' => PaymentStatus::Paid,
            'paid_at' => now(),
            'amount_minor' => 10_000,
            'currency' => 'USD',
            'base_amount_minor' => 380_000,
            'base_currency' => 'UGX',
            'exchange_rate_ppm' => 3_800_000_000,
            'refunded_amount_minor' => 2_500,
        ]);

        // Collected 780,000; refunded USD 25.00 -> UGX 95,000; net 685,000.
        $this->actingAs($staff)
            ->get(route('admin.payments.index'))
            ->assertOk()
            ->assertSee('UGX 780,000')
            ->assertSee('UGX 95,000')
            ->assertSee('UGX 685,000');
    }

    public function test_the_console_is_gated_behind_the_mandatory_two_factor_policy(): void
    {
        config(['security.two_factor.required_roles' => ['manager', 'super_admin']]);

        foreach ([UserRole::Manager, UserRole::SuperAdmin] as $role) {
            $this->actingAs($this->user($role, ['two_factor_required' => false]))
                ->get(route('admin.payments.index'))
                ->assertRedirect(route('profile.security'));
        }
    }
}
