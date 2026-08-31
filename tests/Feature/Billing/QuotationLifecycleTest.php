<?php

namespace Tests\Feature\Billing;

use App\Actions\Billing\ConvertQuotationToInvoice;
use App\Actions\Billing\SaveQuotation;
use App\Actions\Billing\SubmitQuotationRequest;
use App\Actions\Billing\TransitionQuotation;
use App\Enums\AccountStatus;
use App\Enums\InvoiceStatus;
use App\Enums\QuotationRequestStatus;
use App\Enums\QuotationStatus;
use App\Enums\UserRole;
use App\Models\Invoice;
use App\Models\Quotation;
use App\Models\QuotationRequest;
use App\Models\User;
use App\Services\Billing\DocumentNumberGenerator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class QuotationLifecycleTest extends TestCase
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

    private function customer(): User
    {
        return $this->user(UserRole::Customer);
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Bwindi gorilla trek, 4 travellers',
            'currency' => 'UGX',
            'contact_name' => 'Grace Nakato',
            'contact_email' => 'grace@example.com',
            'contact_phone' => '+256700000111',
            'valid_until' => now()->addDays(14)->toDateString(),
            'tax_rate_bps' => 0,
            'items' => [
                ['description' => 'Gorilla permit', 'quantity' => 4, 'unit_price' => '2500000'],
                ['description' => 'Ground transport', 'unit_label' => 'days', 'quantity' => 3, 'unit_price' => '350000'],
            ],
        ], $overrides);
    }

    private function draft(?User $staff = null, array $overrides = []): Quotation
    {
        return app(SaveQuotation::class)->create(
            $staff ?? $this->staff(),
            $this->payload($overrides),
        );
    }

    // ---- Numbering -------------------------------------------------------

    public function test_numbers_are_a_contiguous_per_year_series(): void
    {
        $generator = app(DocumentNumberGenerator::class);

        $numbers = [];

        for ($i = 0; $i < 3; $i++) {
            $numbers[] = $generator->nextInTransaction('INV');
        }

        $this->assertSame(
            ['INV-2026-00001', 'INV-2026-00002', 'INV-2026-00003'],
            $numbers,
        );
    }

    public function test_a_new_year_restarts_the_series(): void
    {
        $generator = app(DocumentNumberGenerator::class);

        $this->assertSame('QTN-2026-00001', $generator->nextInTransaction('QTN'));

        $this->travelTo('2027-01-02 09:00:00');

        $this->assertSame('QTN-2027-00001', $generator->nextInTransaction('QTN'));
    }

    // ---- Drafting --------------------------------------------------------

    public function test_a_draft_totals_its_lines_exactly(): void
    {
        $quotation = $this->draft();

        // 4 × 2,500,000 + 3 × 350,000 = 11,050,000 UGX.
        $this->assertSame(QuotationStatus::Draft, $quotation->status);
        $this->assertSame(11_050_000, $quotation->subtotal_minor);
        $this->assertSame(11_050_000, $quotation->total_minor);
        $this->assertSame(0, $quotation->tax_amount_minor);
        $this->assertCount(2, $quotation->items);
        $this->assertSame(10_000_000, $quotation->items->first()->line_total_minor);
    }

    public function test_tax_is_applied_once_to_the_discounted_subtotal(): void
    {
        $quotation = $this->draft(null, [
            'discount' => '50000',
            'tax_rate_bps' => 1800,
        ]);

        // (11,050,000 − 50,000) × 18% = 1,980,000, exactly.
        $this->assertSame(50_000, $quotation->discount_minor);
        $this->assertSame(1_980_000, $quotation->tax_amount_minor);
        $this->assertSame(12_980_000, $quotation->total_minor);
    }

    public function test_a_discount_larger_than_the_subtotal_is_refused(): void
    {
        // Silently capping would hide a typo that changes what is charged.
        $this->expectException(ValidationException::class);

        $this->draft(null, ['discount' => '99999999']);
    }

    public function test_a_customer_cannot_draft_a_quotation(): void
    {
        $this->expectException(AuthorizationException::class);

        app(SaveQuotation::class)->create($this->customer(), $this->payload());
    }

    public function test_a_suspended_staff_member_cannot_draft(): void
    {
        $staff = $this->user(UserRole::Staff, [
            'status' => AccountStatus::Suspended,
            'two_factor_required' => false,
        ]);

        $this->expectException(AuthorizationException::class);

        app(SaveQuotation::class)->create($staff, $this->payload());
    }

    public function test_a_sent_quotation_cannot_be_edited_in_place(): void
    {
        $staff = $this->staff();
        $quotation = $this->draft($staff);
        app(TransitionQuotation::class)->send($staff, $quotation);

        $this->expectException(ValidationException::class);

        app(SaveQuotation::class)->update($staff, $quotation->fresh(), $this->payload([
            'title' => 'Quietly changed',
        ]));
    }

    public function test_editing_a_draft_replaces_the_line_set_and_restamps_totals(): void
    {
        $staff = $this->staff();
        $quotation = $this->draft($staff);

        $updated = app(SaveQuotation::class)->update($staff, $quotation, $this->payload([
            'items' => [
                ['description' => 'Single permit', 'quantity' => 1, 'unit_price' => '2500000'],
            ],
        ]));

        $this->assertCount(1, $updated->items);
        $this->assertSame(2_500_000, $updated->subtotal_minor);
        $this->assertSame(2_500_000, $updated->total_minor);
    }

    // ---- Sending ---------------------------------------------------------

    public function test_sending_files_a_pdf_and_notifies_the_recipient(): void
    {
        $staff = $this->staff();
        $quotation = $this->draft($staff);

        $sent = app(TransitionQuotation::class)->send($staff, $quotation);

        $this->assertSame(QuotationStatus::Sent, $sent->status);
        $this->assertNotNull($sent->sent_at);

        $document = $sent->currentDocument();
        $this->assertNotNull($document);
        $this->assertSame('application/pdf', $document->mime_type);
        $this->assertTrue($document->is_generated);
        Storage::disk($document->disk)->assertExists($document->path);

        $this->assertDatabaseHas('audit_logs', ['event' => 'quotation.sent']);
    }

    public function test_an_empty_quotation_cannot_be_sent(): void
    {
        $staff = $this->staff();
        $quotation = Quotation::factory()->create();

        $this->expectException(ValidationException::class);

        app(TransitionQuotation::class)->send($staff, $quotation);
    }

    public function test_sending_twice_is_a_no_op(): void
    {
        $staff = $this->staff();
        $quotation = $this->draft($staff);
        $action = app(TransitionQuotation::class);

        $action->send($staff, $quotation);
        $first = $quotation->fresh()->sent_at;

        $this->travelTo(now()->addHour());
        $action->send($staff, $quotation->fresh());

        // The second call must not restamp the send time or file a second PDF.
        $this->assertEquals($first, $quotation->fresh()->sent_at);
        $this->assertSame(1, $quotation->documents()->count());
    }

    public function test_revising_a_sent_offer_returns_it_to_draft_and_bumps_the_revision(): void
    {
        $staff = $this->staff();
        $quotation = $this->draft($staff);
        $action = app(TransitionQuotation::class);

        $action->send($staff, $quotation);
        $revised = $action->revise($staff, $quotation->fresh());

        $this->assertSame(QuotationStatus::Draft, $revised->status);
        $this->assertSame(2, $revised->revision);
        $this->assertNull($revised->sent_at);
    }

    // ---- Customer response -----------------------------------------------

    public function test_a_customer_accepts_their_own_quotation(): void
    {
        $staff = $this->staff();
        $customer = $this->customer();
        $quotation = $this->draft($staff);
        $quotation->forceFill(['customer_id' => $customer->getKey()])->save();

        $action = app(TransitionQuotation::class);
        $action->send($staff, $quotation);

        $accepted = $action->respond($customer, $quotation->fresh(), true);

        $this->assertSame(QuotationStatus::Accepted, $accepted->status);
        $this->assertNotNull($accepted->accepted_at);
        $this->assertDatabaseHas('audit_logs', ['event' => 'quotation.accepted']);
    }

    public function test_a_stranger_cannot_respond_to_someone_elses_quotation(): void
    {
        $staff = $this->staff();
        $customer = $this->customer();
        $quotation = $this->draft($staff);
        $quotation->forceFill(['customer_id' => $customer->getKey()])->save();
        app(TransitionQuotation::class)->send($staff, $quotation);

        $this->expectException(AuthorizationException::class);

        app(TransitionQuotation::class)->respond($this->customer(), $quotation->fresh(), true);
    }

    public function test_a_guest_holding_a_token_cannot_settle_an_owned_quotation(): void
    {
        $staff = $this->staff();
        $customer = $this->customer();
        $quotation = $this->draft($staff);
        $quotation->forceFill(['customer_id' => $customer->getKey()])->save();
        app(TransitionQuotation::class)->send($staff, $quotation);

        // Holding the link is not enough when the offer belongs to an account.
        $this->expectException(AuthorizationException::class);

        app(TransitionQuotation::class)->respond(null, $quotation->fresh(), true);
    }

    public function test_an_expired_offer_cannot_be_accepted_even_before_the_sweep_runs(): void
    {
        $staff = $this->staff();
        $customer = $this->customer();
        $quotation = $this->draft($staff, ['valid_until' => now()->addDay()->toDateString()]);
        $quotation->forceFill(['customer_id' => $customer->getKey()])->save();
        app(TransitionQuotation::class)->send($staff, $quotation);

        $this->travelTo(now()->addDays(5));

        $this->expectException(ValidationException::class);

        app(TransitionQuotation::class)->respond($customer, $quotation->fresh(), true);
    }

    public function test_declining_records_the_reason(): void
    {
        $staff = $this->staff();
        $customer = $this->customer();
        $quotation = $this->draft($staff);
        $quotation->forceFill(['customer_id' => $customer->getKey()])->save();
        app(TransitionQuotation::class)->send($staff, $quotation);

        $declined = app(TransitionQuotation::class)
            ->respond($customer, $quotation->fresh(), false, 'Over our budget this quarter.');

        $this->assertSame(QuotationStatus::Declined, $declined->status);
        $this->assertSame('Over our budget this quarter.', $declined->decline_reason);
    }

    public function test_a_draft_cannot_be_accepted(): void
    {
        $customer = $this->customer();
        $quotation = $this->draft();
        $quotation->forceFill(['customer_id' => $customer->getKey()])->save();

        $this->expectException(ValidationException::class);

        app(TransitionQuotation::class)->respond($customer, $quotation->fresh(), true);
    }

    // ---- Expiry ----------------------------------------------------------

    public function test_the_sweep_expires_only_sent_offers_past_their_date(): void
    {
        $staff = $this->staff();

        $stale = $this->draft($staff, ['valid_until' => now()->addDay()->toDateString()]);
        $fresh = $this->draft($staff, ['valid_until' => now()->addDays(30)->toDateString()]);
        $neverSent = $this->draft($staff, ['valid_until' => now()->addDay()->toDateString()]);

        $action = app(TransitionQuotation::class);
        $action->send($staff, $stale);
        $action->send($staff, $fresh);

        $this->travelTo(now()->addDays(3));

        $this->artisan('quotations:expire')->assertSuccessful();

        $this->assertSame(QuotationStatus::Expired, $stale->fresh()->status);
        $this->assertSame(QuotationStatus::Sent, $fresh->fresh()->status);
        // A draft is not an offer, so it never expires.
        $this->assertSame(QuotationStatus::Draft, $neverSent->fresh()->status);
    }

    public function test_the_sweep_is_safe_to_run_twice(): void
    {
        $staff = $this->staff();
        $quotation = $this->draft($staff, ['valid_until' => now()->addDay()->toDateString()]);
        app(TransitionQuotation::class)->send($staff, $quotation);

        $this->travelTo(now()->addDays(3));

        $this->artisan('quotations:expire')->assertSuccessful();
        $expiredAt = $quotation->fresh()->expired_at;

        $this->travelTo(now()->addHour());
        $this->artisan('quotations:expire')->assertSuccessful();

        $this->assertEquals($expiredAt, $quotation->fresh()->expired_at);
    }

    // ---- Conversion ------------------------------------------------------

    public function test_an_accepted_quotation_converts_to_a_draft_invoice(): void
    {
        $staff = $this->staff();
        $customer = $this->customer();
        $quotation = $this->draft($staff, ['tax_rate_bps' => 1800]);
        $quotation->forceFill(['customer_id' => $customer->getKey()])->save();

        $action = app(TransitionQuotation::class);
        $action->send($staff, $quotation);
        $action->respond($customer, $quotation->fresh(), true);

        $invoice = app(ConvertQuotationToInvoice::class)->execute($staff, $quotation->fresh());

        $this->assertSame(InvoiceStatus::Draft, $invoice->status);
        $this->assertSame($quotation->getKey(), $invoice->quotation_id);
        $this->assertSame($customer->getKey(), $invoice->customer_id);
        $this->assertSame($quotation->fresh()->total_minor, $invoice->total_minor);
        $this->assertCount(2, $invoice->items);
    }

    public function test_converting_twice_returns_the_same_invoice(): void
    {
        $staff = $this->staff();
        $customer = $this->customer();
        $quotation = $this->draft($staff);
        $quotation->forceFill(['customer_id' => $customer->getKey()])->save();

        $transitions = app(TransitionQuotation::class);
        $transitions->send($staff, $quotation);
        $transitions->respond($customer, $quotation->fresh(), true);

        $action = app(ConvertQuotationToInvoice::class);
        $first = $action->execute($staff, $quotation->fresh());
        $second = $action->execute($staff, $quotation->fresh());

        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame(1, Invoice::query()->count());
    }

    public function test_only_an_accepted_quotation_can_be_converted(): void
    {
        $staff = $this->staff();
        $quotation = $this->draft($staff);
        app(TransitionQuotation::class)->send($staff, $quotation);

        $this->expectException(ValidationException::class);

        app(ConvertQuotationToInvoice::class)->execute($staff, $quotation->fresh());
    }

    public function test_revising_a_quotation_never_restates_an_issued_invoice(): void
    {
        $staff = $this->staff();
        $customer = $this->customer();
        $quotation = $this->draft($staff);
        $quotation->forceFill(['customer_id' => $customer->getKey()])->save();

        $transitions = app(TransitionQuotation::class);
        $transitions->send($staff, $quotation);
        $transitions->respond($customer, $quotation->fresh(), true);

        $invoice = app(ConvertQuotationToInvoice::class)->execute($staff, $quotation->fresh());
        $invoicedTotal = $invoice->total_minor;

        // An accepted quotation is terminal, so force the draft state the way a
        // data fix might, then rewrite the lines underneath it.
        $quotation->fresh()->forceFill(['status' => QuotationStatus::Draft])->save();
        app(SaveQuotation::class)->update($staff, $quotation->fresh(), $this->payload([
            'items' => [['description' => 'Something else entirely', 'quantity' => 1, 'unit_price' => '1']],
        ]));

        // The invoice is a snapshot, not a view onto the quotation.
        $this->assertSame($invoicedTotal, $invoice->fresh()->total_minor);
        $this->assertCount(2, $invoice->fresh()->items);
    }

    // ---- Requests --------------------------------------------------------

    public function test_a_guest_request_is_stored_without_a_placeholder_account(): void
    {
        $request = app(SubmitQuotationRequest::class)->execute(null, [
            'service' => 'tours-safaris',
            'details' => 'We are a group of eight looking for a five-day northern circuit safari.',
            'contact_name' => 'Peter Okello',
            'contact_email' => 'Peter@Example.com',
            'contact_phone' => '+256 700 111 222',
        ], (string) Str::uuid());

        $this->assertNull($request->customer_id);
        $this->assertTrue($request->isGuest());
        $this->assertSame('peter@example.com', $request->contact_email);
        $this->assertSame('+256700111222', $request->contact_phone);
        $this->assertSame(QuotationRequestStatus::New, $request->status);
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $request->tracking_token);
    }

    public function test_a_replayed_request_returns_the_first_one(): void
    {
        $key = (string) Str::uuid();
        $payload = [
            'service' => 'car-hire',
            'details' => 'Two four-wheel drives for a fortnight of field work in Gulu.',
            'contact_name' => 'Peter Okello',
            'contact_email' => 'peter@example.com',
            'contact_phone' => '+256700111222',
        ];

        $action = app(SubmitQuotationRequest::class);
        $first = $action->execute(null, $payload, $key);
        $second = $action->execute(null, $payload, $key);

        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame(1, QuotationRequest::query()->count());
    }

    public function test_quoting_against_a_request_advances_it_through_its_own_lifecycle(): void
    {
        $staff = $this->staff();
        $customer = $this->customer();
        $request = QuotationRequest::factory()->forCustomer($customer)->create();

        $quotation = app(SaveQuotation::class)->create($staff, $this->payload(), $request);
        $this->assertSame(QuotationRequestStatus::InReview, $request->fresh()->status);

        $transitions = app(TransitionQuotation::class);
        $transitions->send($staff, $quotation);
        $this->assertSame(QuotationRequestStatus::Quoted, $request->fresh()->status);

        $transitions->respond($customer, $quotation->fresh(), true);
        app(ConvertQuotationToInvoice::class)->execute($staff, $quotation->fresh());

        $this->assertSame(QuotationRequestStatus::Closed, $request->fresh()->status);
        $this->assertNotNull($request->fresh()->closed_at);
    }
}
