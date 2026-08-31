<?php

namespace Tests\Feature\Billing;

use App\Actions\Billing\TransitionInvoice;
use App\Actions\Payments\CreatePaymentIntent;
use App\Actions\Payments\SettlePayment;
use App\Enums\AccountStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentProvider;
use App\Enums\UserRole;
use App\Models\Invoice;
use App\Models\User;
use App\Support\Payments\PayableRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InvoiceLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo('2026-08-20 09:00:00');
        Notification::fake();
        Storage::fake('local');
    }

    private function user(UserRole $role, array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'role' => $role,
            'status' => AccountStatus::Active,
            'email_verified_at' => now(),
            'phone' => '+256700'.fake()->unique()->numerify('######'),
        ], $attributes));
    }

    private function staff(): User
    {
        return $this->user(UserRole::Staff, ['two_factor_required' => false]);
    }

    private function manager(): User
    {
        return $this->user(UserRole::Manager, ['two_factor_required' => false]);
    }

    private function customer(): User
    {
        return $this->user(UserRole::Customer);
    }

    /** @return array{0: Invoice, 1: User} */
    private function draftInvoice(array $overrides = [], int $depositMinor = 0): array
    {
        $customer = $this->customer();

        $invoice = Invoice::factory()
            ->forCustomer($customer)
            ->withItems([
                ['description' => 'Gorilla permit', 'quantity' => 4, 'unit_price_minor' => 2_500_000],
                ['description' => 'Ground transport', 'quantity' => 3, 'unit_price_minor' => 350_000],
            ])
            ->create(array_merge([
                'deposit_minor' => $depositMinor > 0 ? $depositMinor : null,
            ], $overrides));

        return [$invoice->fresh('items'), $customer];
    }

    // ---- Issuing ---------------------------------------------------------

    public function test_issuing_files_a_pdf_sets_the_due_date_and_notifies(): void
    {
        [$invoice] = $this->draftInvoice(['due_on' => null]);
        $staff = $this->staff();

        $issued = app(TransitionInvoice::class)->issue($staff, $invoice);

        $this->assertSame(InvoiceStatus::Issued, $issued->status);
        $this->assertNotNull($issued->issued_at);
        // Default terms are 14 days from issue.
        $this->assertSame('2026-09-03', $issued->due_on->toDateString());

        $document = $issued->currentDocument();
        $this->assertNotNull($document);
        $this->assertSame('application/pdf', $document->mime_type);
        Storage::disk($document->disk)->assertExists($document->path);

        $this->assertDatabaseHas('audit_logs', ['event' => 'invoice.issued']);
    }

    public function test_issuing_twice_files_one_pdf(): void
    {
        [$invoice] = $this->draftInvoice();
        $staff = $this->staff();
        $action = app(TransitionInvoice::class);

        $action->issue($staff, $invoice);
        $action->issue($staff, $invoice->fresh());

        $this->assertSame(1, $invoice->documents()->count());
    }

    public function test_an_empty_invoice_cannot_be_issued(): void
    {
        $invoice = Invoice::factory()->create();

        $this->expectException(ValidationException::class);

        app(TransitionInvoice::class)->issue($this->staff(), $invoice);
    }

    public function test_a_customer_cannot_issue_an_invoice(): void
    {
        [$invoice, $customer] = $this->draftInvoice();

        $this->expectException(AuthorizationException::class);

        app(TransitionInvoice::class)->issue($customer, $invoice);
    }

    // ---- Payability ------------------------------------------------------

    public function test_a_draft_invoice_is_never_payable(): void
    {
        [$invoice] = $this->draftInvoice();

        // A draft is internal. Showing an amount due for one would invite a
        // payment against a document the customer has never seen.
        $this->assertFalse($invoice->acceptsPayment());
        $this->assertSame(0, $invoice->outstandingAmountMinor());
    }

    public function test_an_issued_invoice_owes_its_total(): void
    {
        [$invoice] = $this->draftInvoice();
        app(TransitionInvoice::class)->issue($this->staff(), $invoice);

        $invoice->refresh();
        $this->assertTrue($invoice->acceptsPayment());
        $this->assertSame(11_050_000, $invoice->outstandingAmountMinor());
    }

    public function test_settling_in_full_marks_the_invoice_paid(): void
    {
        [$invoice, $customer] = $this->draftInvoice();
        $staff = $this->staff();
        app(TransitionInvoice::class)->issue($staff, $invoice);

        $payment = app(CreatePaymentIntent::class)->execute(
            $customer,
            $invoice->fresh(),
            PaymentProvider::BankTransfer,
            (string) Str::uuid(),
        );

        app(SettlePayment::class)->execute(payment: $payment, actor: $staff, source: 'manual');

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $this->assertNotNull($invoice->paid_at);
        $this->assertSame(0, $invoice->outstandingAmountMinor());
        $this->assertFalse($invoice->acceptsPayment());
    }

    public function test_a_deposit_invoice_collects_the_deposit_first(): void
    {
        [$invoice, $customer] = $this->draftInvoice([], 3_000_000);
        $staff = $this->staff();
        app(TransitionInvoice::class)->issue($staff, $invoice);
        $invoice->refresh();

        // Asking for the whole total before the agreed deposit would charge
        // more than was agreed.
        $this->assertTrue($invoice->hasDeposit());
        $this->assertSame(3_000_000, $invoice->outstandingAmountMinor());
        $this->assertSame('Deposit', $invoice->nextPaymentLabel());

        $payment = app(CreatePaymentIntent::class)->execute(
            $customer,
            $invoice,
            PaymentProvider::BankTransfer,
            (string) Str::uuid(),
        );
        $this->assertSame(3_000_000, $payment->amount_minor);

        app(SettlePayment::class)->execute(payment: $payment, actor: $staff, source: 'manual');

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::PartiallyPaid, $invoice->status);
        $this->assertNull($invoice->paid_at);
        // The balance becomes collectable once the deposit is in.
        $this->assertSame(8_050_000, $invoice->outstandingAmountMinor());
        $this->assertSame('Balance', $invoice->nextPaymentLabel());
    }

    public function test_paying_the_balance_after_a_deposit_marks_it_paid(): void
    {
        [$invoice, $customer] = $this->draftInvoice([], 3_000_000);
        $staff = $this->staff();
        app(TransitionInvoice::class)->issue($staff, $invoice);

        foreach ([3_000_000, 8_050_000] as $expected) {
            $payment = app(CreatePaymentIntent::class)->execute(
                $customer,
                $invoice->fresh(),
                PaymentProvider::BankTransfer,
                (string) Str::uuid(),
            );

            $this->assertSame($expected, $payment->amount_minor);
            app(SettlePayment::class)->execute(payment: $payment, actor: $staff, source: 'manual');
        }

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $this->assertSame(0, $invoice->outstandingAmountMinor());
        $this->assertSame(11_050_000, $invoice->settledAmountMinor());
    }

    public function test_settlement_is_idempotent(): void
    {
        [$invoice, $customer] = $this->draftInvoice();
        $staff = $this->staff();
        app(TransitionInvoice::class)->issue($staff, $invoice);

        $payment = app(CreatePaymentIntent::class)->execute(
            $customer,
            $invoice->fresh(),
            PaymentProvider::BankTransfer,
            (string) Str::uuid(),
        );

        $action = app(SettlePayment::class);
        $action->execute(payment: $payment, actor: $staff, source: 'manual');
        $paidAt = $invoice->fresh()->paid_at;

        $this->travelTo(now()->addHour());
        $action->execute(payment: $payment->fresh(), actor: $staff, source: 'manual');

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        // A duplicate webhook must not restamp the payment time or double-count.
        $this->assertEquals($paidAt, $invoice->paid_at);
        $this->assertSame(11_050_000, $invoice->settledAmountMinor());
        $this->assertSame(1, $invoice->paymentAllocations()->count());
    }

    public function test_an_invoice_is_reachable_through_the_payable_registry(): void
    {
        [$invoice] = $this->draftInvoice();
        app(TransitionInvoice::class)->issue($this->staff(), $invoice);

        $this->assertSame('invoices', PayableRegistry::segmentFor($invoice->fresh()));
        $this->assertSame(Invoice::class, PayableRegistry::classFor('invoices'));
        $this->assertNotNull(PayableRegistry::checkoutUrl($invoice->fresh()));
    }

    // ---- Overdue ---------------------------------------------------------

    public function test_overdue_is_derived_from_the_due_date_and_the_balance(): void
    {
        [$invoice] = $this->draftInvoice(['due_on' => '2026-08-25']);
        app(TransitionInvoice::class)->issue($this->staff(), $invoice);

        $this->assertFalse($invoice->fresh()->isOverdue());

        $this->travelTo('2026-08-27 09:00:00');

        $this->assertTrue($invoice->fresh()->isOverdue());
        $this->assertSame(1, Invoice::query()->overdue()->count());
    }

    public function test_a_paid_invoice_is_never_overdue(): void
    {
        [$invoice, $customer] = $this->draftInvoice(['due_on' => '2026-08-25']);
        $staff = $this->staff();
        app(TransitionInvoice::class)->issue($staff, $invoice);

        $payment = app(CreatePaymentIntent::class)->execute(
            $customer,
            $invoice->fresh(),
            PaymentProvider::BankTransfer,
            (string) Str::uuid(),
        );
        app(SettlePayment::class)->execute(payment: $payment, actor: $staff, source: 'manual');

        $this->travelTo('2026-09-30 09:00:00');

        $this->assertFalse($invoice->fresh()->isOverdue());
        $this->assertSame(0, Invoice::query()->overdue()->count());
    }

    // ---- Cancel and void -------------------------------------------------

    public function test_an_unpaid_invoice_can_be_cancelled_with_a_reason(): void
    {
        [$invoice] = $this->draftInvoice();
        $staff = $this->staff();
        app(TransitionInvoice::class)->issue($staff, $invoice);

        $cancelled = app(TransitionInvoice::class)
            ->cancel($staff, $invoice->fresh(), 'Customer withdrew before travelling.');

        $this->assertSame(InvoiceStatus::Cancelled, $cancelled->status);
        $this->assertSame(0, $cancelled->outstandingAmountMinor());
        $this->assertFalse($cancelled->acceptsPayment());
    }

    public function test_cancelling_requires_a_reason(): void
    {
        [$invoice] = $this->draftInvoice();
        $staff = $this->staff();
        app(TransitionInvoice::class)->issue($staff, $invoice);

        $this->expectException(ValidationException::class);

        app(TransitionInvoice::class)->cancel($staff, $invoice->fresh(), '');
    }

    public function test_an_invoice_with_money_against_it_cannot_be_cancelled(): void
    {
        [$invoice, $customer] = $this->draftInvoice([], 3_000_000);
        $staff = $this->staff();
        app(TransitionInvoice::class)->issue($staff, $invoice);

        $payment = app(CreatePaymentIntent::class)->execute(
            $customer,
            $invoice->fresh(),
            PaymentProvider::BankTransfer,
            (string) Str::uuid(),
        );
        app(SettlePayment::class)->execute(payment: $payment, actor: $staff, source: 'manual');

        // Money has moved, so the correction is a void, not a cancellation.
        $this->expectException(ValidationException::class);

        app(TransitionInvoice::class)->cancel($staff, $invoice->fresh(), 'Changed their mind.');
    }

    public function test_only_a_manager_can_void(): void
    {
        [$invoice] = $this->draftInvoice();
        $staff = $this->staff();
        app(TransitionInvoice::class)->issue($staff, $invoice);

        $this->expectException(AuthorizationException::class);

        app(TransitionInvoice::class)->void($staff, $invoice->fresh(), 'Raised against the wrong account.');
    }

    public function test_a_manager_voids_a_paid_invoice_and_the_amount_is_recorded(): void
    {
        [$invoice, $customer] = $this->draftInvoice();
        $staff = $this->staff();
        app(TransitionInvoice::class)->issue($staff, $invoice);

        $payment = app(CreatePaymentIntent::class)->execute(
            $customer,
            $invoice->fresh(),
            PaymentProvider::BankTransfer,
            (string) Str::uuid(),
        );
        app(SettlePayment::class)->execute(payment: $payment, actor: $staff, source: 'manual');

        $voided = app(TransitionInvoice::class)
            ->void($this->manager(), $invoice->fresh(), 'Raised against the wrong account.');

        $this->assertSame(InvoiceStatus::Void, $voided->status);
        $this->assertSame(0, $voided->outstandingAmountMinor());
        $this->assertDatabaseHas('audit_logs', ['event' => 'invoice.voided']);
    }

    public function test_a_cancelled_invoice_is_terminal(): void
    {
        [$invoice] = $this->draftInvoice();
        $staff = $this->staff();
        app(TransitionInvoice::class)->issue($staff, $invoice);
        app(TransitionInvoice::class)->cancel($staff, $invoice->fresh(), 'Duplicate of another invoice.');

        $this->expectException(ValidationException::class);

        app(TransitionInvoice::class)->issue($staff, $invoice->fresh());
    }

    public function test_every_invoice_status_is_reachable(): void
    {
        // A status nobody can reach is a lie in the enum, so prove each one is
        // produced by a real path rather than only by a factory.
        [$invoice, $customer] = $this->draftInvoice([], 3_000_000);
        $staff = $this->staff();

        $this->assertSame(InvoiceStatus::Draft, $invoice->status);

        app(TransitionInvoice::class)->issue($staff, $invoice);
        $this->assertSame(InvoiceStatus::Issued, $invoice->fresh()->status);

        $deposit = app(CreatePaymentIntent::class)->execute(
            $customer, $invoice->fresh(), PaymentProvider::BankTransfer, (string) Str::uuid(),
        );
        app(SettlePayment::class)->execute(payment: $deposit, actor: $staff, source: 'manual');
        $this->assertSame(InvoiceStatus::PartiallyPaid, $invoice->fresh()->status);

        $balance = app(CreatePaymentIntent::class)->execute(
            $customer, $invoice->fresh(), PaymentProvider::BankTransfer, (string) Str::uuid(),
        );
        app(SettlePayment::class)->execute(payment: $balance, actor: $staff, source: 'manual');
        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);

        app(TransitionInvoice::class)->void($this->manager(), $invoice->fresh(), 'Superseded by a credit note.');
        $this->assertSame(InvoiceStatus::Void, $invoice->fresh()->status);

        // Cancelled comes from a separate document, since Paid cannot reach it.
        [$second] = $this->draftInvoice();
        app(TransitionInvoice::class)->issue($staff, $second);
        app(TransitionInvoice::class)->cancel($staff, $second->fresh(), 'Raised in error.');
        $this->assertSame(InvoiceStatus::Cancelled, $second->fresh()->status);
    }
}
