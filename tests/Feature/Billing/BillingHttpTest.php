<?php

namespace Tests\Feature\Billing;

use App\Actions\Billing\ConvertQuotationToInvoice;
use App\Actions\Billing\SaveQuotation;
use App\Actions\Billing\TransitionInvoice;
use App\Actions\Billing\TransitionQuotation;
use App\Enums\AccountStatus;
use App\Enums\InvoiceStatus;
use App\Enums\QuotationStatus;
use App\Enums\UserRole;
use App\Models\Invoice;
use App\Models\Quotation;
use App\Models\QuotationRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class BillingHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo('2026-08-20 09:00:00');
        Notification::fake();
        Storage::fake('local');

        // Managers sit under the mandatory-2FA policy, which would redirect to
        // the security page before any billing route is reached. That gate is
        // covered by its own middleware tests; relaxing it here keeps these
        // tests about billing.
        config(['security.two_factor.required_roles' => []]);
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

    /** @return array<string, mixed> */
    private function quotationPayload(array $overrides = []): array
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
            ],
        ], $overrides);
    }

    private function sentQuotationFor(User $customer): Quotation
    {
        $staff = $this->staff();
        $quotation = app(SaveQuotation::class)->create($staff, $this->quotationPayload());
        $quotation->forceFill(['customer_id' => $customer->getKey()])->save();
        app(TransitionQuotation::class)->send($staff, $quotation);

        return $quotation->fresh(['items']);
    }

    private function issuedInvoiceFor(User $customer): Invoice
    {
        $staff = $this->staff();
        $quotation = $this->sentQuotationFor($customer);
        app(TransitionQuotation::class)->respond($customer, $quotation, true);
        $invoice = app(ConvertQuotationToInvoice::class)->execute($staff, $quotation->fresh());
        app(TransitionInvoice::class)->issue($staff, $invoice);

        return $invoice->fresh(['items']);
    }

    // ---- Public quotation request ----------------------------------------

    public function test_the_public_form_renders_and_posts_somewhere_real(): void
    {
        $this->get(route('request-quotation'))
            ->assertOk()
            ->assertSee(route('quotation-requests.store'))
            ->assertSee('Request a quotation');
    }

    public function test_a_guest_submits_a_request_and_lands_on_a_tracking_page(): void
    {
        $response = $this->post(route('quotation-requests.store'), [
            'service' => 'tours-safaris',
            'details' => 'Eight of us want a five-day northern circuit safari in December.',
            'contact_name' => 'Peter Okello',
            'contact_email' => 'peter@example.com',
            'contact_phone' => '+256700111222',
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $request = QuotationRequest::query()->sole();
        $response->assertRedirect(route('quotation-requests.track', ['token' => $request->tracking_token]));

        $this->get(route('quotation-requests.track', ['token' => $request->tracking_token]))
            ->assertOk()
            ->assertSee($request->reference);
    }

    public function test_a_short_description_is_rejected(): void
    {
        $this->post(route('quotation-requests.store'), [
            'service' => 'car-hire',
            'details' => 'Need a car',
            'contact_name' => 'Peter Okello',
            'contact_email' => 'peter@example.com',
            'contact_phone' => '+256700111222',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertSessionHasErrors('details');

        $this->assertDatabaseCount('quotation_requests', 0);
    }

    public function test_a_signed_in_customer_lands_in_their_portal(): void
    {
        $customer = $this->customer();

        $response = $this->actingAs($customer)->post(route('quotation-requests.store'), [
            'service' => 'airport-transfers',
            'details' => 'Regular Entebbe pickups for visiting staff over the next quarter.',
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $request = QuotationRequest::query()->sole();
        // Identity comes from the account, never from the form.
        $this->assertSame($customer->getKey(), $request->customer_id);
        $this->assertSame($customer->email, $request->contact_email);
        $response->assertRedirect(route('portal.quotation-requests.show', $request));
    }

    public function test_a_bad_tracking_token_is_a_404(): void
    {
        QuotationRequest::factory()->create();

        $this->get('/track/quotation-request/'.str_repeat('a', 64))->assertNotFound();
    }

    // ---- Admin console ---------------------------------------------------

    public function test_staff_draft_send_and_convert_a_quotation_over_http(): void
    {
        $staff = $this->staff();

        $this->actingAs($staff)->get(route('admin.quotations.create'))->assertOk();

        $this->actingAs($staff)
            ->post(route('admin.quotations.store'), $this->quotationPayload())
            ->assertRedirect();

        $quotation = Quotation::query()->sole();
        $this->assertSame(QuotationStatus::Draft, $quotation->status);

        $this->actingAs($staff)->get(route('admin.quotations.show', $quotation))->assertOk();
        $this->actingAs($staff)->get(route('admin.quotations.edit', $quotation))->assertOk();

        $this->actingAs($staff)
            ->post(route('admin.quotations.send', $quotation))
            ->assertRedirect();

        $this->assertSame(QuotationStatus::Sent, $quotation->fresh()->status);
    }

    public function test_a_customer_cannot_reach_the_billing_console(): void
    {
        $customer = $this->customer();
        $quotation = $this->sentQuotationFor($customer);
        $invoice = $this->issuedInvoiceFor($this->customer());

        $this->actingAs($customer)->get(route('admin.quotations.index'))->assertForbidden();
        $this->actingAs($customer)->get(route('admin.quotations.show', $quotation))->assertForbidden();
        $this->actingAs($customer)->get(route('admin.invoices.index'))->assertForbidden();
        $this->actingAs($customer)->get(route('admin.invoices.show', $invoice))->assertForbidden();
        $this->actingAs($customer)->get(route('admin.quotation-requests.index'))->assertForbidden();
    }

    public function test_a_customer_cannot_draft_a_quotation_over_http(): void
    {
        $this->actingAs($this->customer())
            ->post(route('admin.quotations.store'), $this->quotationPayload())
            ->assertForbidden();

        $this->assertDatabaseCount('quotations', 0);
    }

    public function test_staff_cannot_void_an_invoice_but_a_manager_can(): void
    {
        $invoice = $this->issuedInvoiceFor($this->customer());

        $this->actingAs($this->staff())
            ->post(route('admin.invoices.void', $invoice), ['reason' => 'Raised against the wrong account.'])
            ->assertForbidden();

        $this->assertSame(InvoiceStatus::Issued, $invoice->fresh()->status);

        $this->actingAs($this->manager())
            ->post(route('admin.invoices.void', $invoice), ['reason' => 'Raised against the wrong account.'])
            ->assertRedirect();

        $this->assertSame(InvoiceStatus::Void, $invoice->fresh()->status);
    }

    public function test_cancelling_over_http_requires_a_reason(): void
    {
        $invoice = $this->issuedInvoiceFor($this->customer());

        $this->actingAs($this->staff())
            ->post(route('admin.invoices.cancel', $invoice), ['reason' => ''])
            ->assertSessionHasErrors('reason');

        $this->assertSame(InvoiceStatus::Issued, $invoice->fresh()->status);
    }

    public function test_the_console_lists_and_filters(): void
    {
        $staff = $this->staff();
        $this->sentQuotationFor($this->customer());
        app(SaveQuotation::class)->create($staff, $this->quotationPayload(['title' => 'Draft only offer']));

        $this->actingAs($staff)
            ->get(route('admin.quotations.index', ['status' => QuotationStatus::Draft->value]))
            ->assertOk()
            ->assertSee('Draft only offer')
            ->assertDontSee('Bwindi gorilla trek, 4 travellers');

        $this->actingAs($staff)->get(route('admin.invoices.index'))->assertOk();
        $this->actingAs($staff)->get(route('admin.quotation-requests.index'))->assertOk();
    }

    // ---- Customer portal -------------------------------------------------

    public function test_a_customer_sees_and_accepts_their_quotation(): void
    {
        $customer = $this->customer();
        $quotation = $this->sentQuotationFor($customer);

        $this->actingAs($customer)
            ->get(route('portal.quotations.index'))
            ->assertOk()
            ->assertSee($quotation->number);

        $this->actingAs($customer)
            ->get(route('portal.quotations.show', $quotation))
            ->assertOk()
            ->assertSee('Accept this quotation');

        $this->actingAs($customer)
            ->post(route('portal.quotations.respond', $quotation), ['decision' => 'accept'])
            ->assertRedirect(route('portal.quotations.show', $quotation));

        $this->assertSame(QuotationStatus::Accepted, $quotation->fresh()->status);
    }

    public function test_declining_over_http_requires_a_reason(): void
    {
        $customer = $this->customer();
        $quotation = $this->sentQuotationFor($customer);

        $this->actingAs($customer)
            ->post(route('portal.quotations.respond', $quotation), ['decision' => 'decline'])
            ->assertSessionHasErrors('reason');

        $this->assertSame(QuotationStatus::Sent, $quotation->fresh()->status);
    }

    public function test_a_draft_quotation_never_resolves_in_the_portal(): void
    {
        $customer = $this->customer();
        $staff = $this->staff();
        $quotation = app(SaveQuotation::class)->create($staff, $this->quotationPayload());
        $quotation->forceFill(['customer_id' => $customer->getKey()])->save();

        // The binding filters to customer-visible statuses, so an internal draft
        // is a 404 even for the customer it is addressed to.
        $this->actingAs($customer)
            ->get(route('portal.quotations.show', $quotation->fresh()))
            ->assertNotFound();

        $this->actingAs($customer)
            ->get(route('portal.quotations.index'))
            ->assertOk()
            ->assertDontSee($quotation->number);
    }

    public function test_a_stranger_gets_a_404_not_a_403(): void
    {
        $quotation = $this->sentQuotationFor($this->customer());
        $invoice = $this->issuedInvoiceFor($this->customer());
        $stranger = $this->customer();

        // A scoped binding hides the record entirely: a stranger learns nothing
        // about whose it is.
        $this->actingAs($stranger)->get(route('portal.quotations.show', $quotation))->assertNotFound();
        $this->actingAs($stranger)->get(route('portal.invoices.show', $invoice))->assertNotFound();
        $this->actingAs($stranger)
            ->post(route('portal.quotations.respond', $quotation), ['decision' => 'accept'])
            ->assertNotFound();
    }

    public function test_a_customer_sees_a_pay_now_control_on_an_issued_invoice(): void
    {
        $customer = $this->customer();
        $invoice = $this->issuedInvoiceFor($customer);

        $this->actingAs($customer)
            ->get(route('portal.invoices.show', $invoice))
            ->assertOk()
            ->assertSee($invoice->number)
            ->assertSee('Pay now')
            ->assertSee(route('payments.checkout', ['invoices', $invoice->number]));
    }

    public function test_a_draft_invoice_is_not_reachable_in_the_portal(): void
    {
        $customer = $this->customer();
        $staff = $this->staff();
        $quotation = $this->sentQuotationFor($customer);
        app(TransitionQuotation::class)->respond($customer, $quotation, true);
        $invoice = app(ConvertQuotationToInvoice::class)->execute($staff, $quotation->fresh());

        $this->assertSame(InvoiceStatus::Draft, $invoice->status);

        $this->actingAs($customer)->get(route('portal.invoices.show', $invoice))->assertNotFound();
        $this->actingAs($customer)
            ->get(route('portal.invoices.index'))
            ->assertOk()
            ->assertDontSee($invoice->number);
    }

    public function test_internal_notes_never_reach_a_customer_screen(): void
    {
        $customer = $this->customer();
        $staff = $this->staff();
        $secret = 'Margin is thin, do not discount further.';

        $quotation = app(SaveQuotation::class)->create($staff, $this->quotationPayload([
            'internal_notes' => $secret,
        ]));
        $quotation->forceFill(['customer_id' => $customer->getKey()])->save();
        app(TransitionQuotation::class)->send($staff, $quotation);

        $this->actingAs($customer)
            ->get(route('portal.quotations.show', $quotation->fresh()))
            ->assertOk()
            ->assertDontSee($secret);

        // Staff do see it.
        $this->actingAs($staff)
            ->get(route('admin.quotations.show', $quotation->fresh()))
            ->assertOk()
            ->assertSee($secret);
    }

    // ---- Guest tracking --------------------------------------------------

    public function test_a_guest_reviews_and_accepts_through_their_token_link(): void
    {
        $staff = $this->staff();
        $quotation = app(SaveQuotation::class)->create($staff, $this->quotationPayload());
        app(TransitionQuotation::class)->send($staff, $quotation);
        $token = $quotation->fresh()->tracking_token;

        $this->get(route('quotations.track', ['token' => $token]))
            ->assertOk()
            ->assertSee($quotation->number)
            ->assertSee('Accept this quotation');

        $this->post(route('quotations.track.respond', ['token' => $token]), ['decision' => 'accept'])
            ->assertRedirect(route('quotations.track', ['token' => $token]));

        $this->assertSame(QuotationStatus::Accepted, $quotation->fresh()->status);
    }

    public function test_a_guest_token_cannot_settle_a_quotation_owned_by_an_account(): void
    {
        $customer = $this->customer();
        $quotation = $this->sentQuotationFor($customer);
        $token = $quotation->tracking_token;

        // Holding the link is not enough when the offer belongs to an account.
        $this->post(route('quotations.track.respond', ['token' => $token]), ['decision' => 'accept'])
            ->assertForbidden();

        $this->assertSame(QuotationStatus::Sent, $quotation->fresh()->status);
    }

    public function test_a_draft_quotation_is_not_reachable_by_token(): void
    {
        $quotation = app(SaveQuotation::class)->create($this->staff(), $this->quotationPayload());

        $this->get(route('quotations.track', ['token' => $quotation->tracking_token]))
            ->assertNotFound();
    }

    public function test_a_guest_invoice_link_shows_the_document_but_offers_no_checkout(): void
    {
        $staff = $this->staff();
        $quotation = app(SaveQuotation::class)->create($staff, $this->quotationPayload());
        app(TransitionQuotation::class)->send($staff, $quotation);
        app(TransitionQuotation::class)->respond(null, $quotation->fresh(), true);
        $invoice = app(ConvertQuotationToInvoice::class)->execute($staff, $quotation->fresh());
        app(TransitionInvoice::class)->issue($staff, $invoice);

        $this->get(route('invoices.track', ['token' => $invoice->fresh()->tracking_token]))
            ->assertOk()
            ->assertSee($invoice->number)
            // Payment requires an account, so a guest is invited to create one
            // rather than shown a checkout button that would refuse them.
            ->assertSee('Create an account')
            ->assertDontSee('Pay now');
    }
}
