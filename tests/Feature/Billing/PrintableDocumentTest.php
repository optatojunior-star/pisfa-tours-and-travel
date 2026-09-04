<?php

namespace Tests\Feature\Billing;

use App\Actions\Billing\ConvertQuotationToInvoice;
use App\Actions\Billing\SaveQuotation;
use App\Actions\Billing\TransitionInvoice;
use App\Actions\Billing\TransitionQuotation;
use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Models\Invoice;
use App\Models\Quotation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Getting a copy of an invoice or quotation you can keep.
 *
 * The PDFs were being generated and filed all along, and nothing outside the
 * admin console ever linked to one: a customer could read their invoice on
 * screen and had no way whatsoever to print it or save it. A guest holding a
 * tracking link was worse off, because the document download requires an
 * account they do not have.
 *
 * These tests hold that shut. They also check the part that matters more than
 * the printing — that a printable copy is no easier to reach than the document
 * it prints.
 */
class PrintableDocumentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo('2026-08-20 09:00:00');
        Notification::fake();
        Storage::fake('local');
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

    private function customer(): User
    {
        return $this->user(UserRole::Customer);
    }

    private function sentQuotationFor(User $customer): Quotation
    {
        $staff = $this->staff();

        $quotation = app(SaveQuotation::class)->create($staff, [
            'title' => 'Bwindi gorilla trek, 4 travellers',
            'currency' => 'UGX',
            'contact_name' => 'Grace Nakato',
            'contact_email' => 'grace@example.com',
            'contact_phone' => '+256700000111',
            'valid_until' => now()->addDays(14)->toDateString(),
            'tax_rate_bps' => 1800,
            'items' => [
                ['description' => 'Gorilla permit', 'quantity' => 4, 'unit_price' => '2500000'],
                ['description' => 'Ground transport', 'quantity' => 1, 'unit_price' => '900000'],
            ],
        ]);

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

    // ------------------------------------------------------------- the customer

    public function test_a_customer_can_print_their_own_invoice(): void
    {
        $customer = $this->customer();
        $invoice = $this->issuedInvoiceFor($customer);

        $this->actingAs($customer)
            ->get(route('portal.invoices.print', $invoice))
            ->assertOk()
            ->assertSee($invoice->number)
            ->assertSee($invoice->formattedTotal())
            // The button is what makes it a printable page rather than a page.
            ->assertSee('window.print()', false);
    }

    public function test_the_invoice_page_offers_the_customer_a_way_to_keep_a_copy(): void
    {
        $customer = $this->customer();
        $invoice = $this->issuedInvoiceFor($customer);

        $this->actingAs($customer)
            ->get(route('portal.invoices.show', $invoice))
            ->assertOk()
            ->assertSee(route('portal.invoices.print', $invoice), false);
    }

    public function test_a_customer_cannot_print_somebody_elses_invoice(): void
    {
        $invoice = $this->issuedInvoiceFor($this->customer());
        $stranger = $this->customer();

        $this->actingAs($stranger)
            ->get(route('portal.invoices.print', $invoice))
            ->assertForbidden();
    }

    public function test_a_guest_cannot_print_an_invoice_without_the_token(): void
    {
        $invoice = $this->issuedInvoiceFor($this->customer());

        $this->get(route('portal.invoices.print', $invoice))
            ->assertRedirect(route('login'));
    }

    public function test_a_customer_can_print_their_own_quotation(): void
    {
        $customer = $this->customer();
        $quotation = $this->sentQuotationFor($customer);

        $this->actingAs($customer)
            ->get(route('portal.quotations.print', $quotation))
            ->assertOk()
            ->assertSee($quotation->number)
            // Said on the document itself, because a printed page with a total
            // on it is exactly what somebody pays against by mistake.
            ->assertSee('This is a quotation, not an invoice.');
    }

    // ---------------------------------------------------------------- the guest

    public function test_a_guest_prints_by_the_same_token_that_shows_the_document(): void
    {
        $invoice = $this->issuedInvoiceFor($this->customer());

        $this->get(route('invoices.track.print', $invoice->tracking_token))
            ->assertOk()
            ->assertSee($invoice->number);
    }

    public function test_a_wrong_token_is_not_found_rather_than_refused(): void
    {
        $this->issuedInvoiceFor($this->customer());

        // Well-formed but not anybody's, so the shape check passes and the
        // lookup is what fails — 404, revealing nothing about what exists.
        $this->get(route('invoices.track.print', str_repeat('a', 64)))
            ->assertNotFound();
    }

    public function test_a_malformed_token_never_reaches_the_database(): void
    {
        $this->get('/track/invoice/not-a-token/print')->assertNotFound();
    }

    public function test_the_guest_tracking_page_offers_a_printable_copy(): void
    {
        $invoice = $this->issuedInvoiceFor($this->customer());

        $this->get(route('invoices.track', $invoice->tracking_token))
            ->assertOk()
            ->assertSee(route('invoices.track.print', $invoice->tracking_token), false);
    }

    /** A printed invoice served to a search engine is a data leak. */
    public function test_a_printable_copy_is_never_indexed(): void
    {
        $invoice = $this->issuedInvoiceFor($this->customer());

        $this->get(route('invoices.track.print', $invoice->tracking_token))
            ->assertOk()
            ->assertSee('noindex', false);
    }

    // ---------------------------------------------------------------- the staff

    public function test_staff_can_print_an_invoice_from_the_console(): void
    {
        $invoice = $this->issuedInvoiceFor($this->customer());

        $this->actingAs($this->staff())
            ->get(route('admin.invoices.print', $invoice))
            ->assertOk()
            ->assertSee($invoice->number);
    }

    public function test_the_printed_invoice_carries_the_company_address(): void
    {
        // It prints on the document the customer keeps, which is the whole
        // reason the address is configurable rather than hard-coded.
        $invoice = $this->issuedInvoiceFor($this->customer());

        $this->actingAs($this->staff())
            ->get(route('admin.invoices.print', $invoice))
            ->assertOk()
            ->assertSee(config('pisfa.company.address'));
    }
}
