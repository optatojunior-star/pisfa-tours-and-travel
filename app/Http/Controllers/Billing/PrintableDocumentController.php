<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Quotation;
use App\Services\Settings\SettingsRepository;
use Illuminate\View\View;

/**
 * A printable copy of an invoice or a quotation.
 *
 * The PDFs existed and were filed correctly, and nothing outside the admin
 * console ever linked to one — a customer could read their invoice on screen
 * and had no way at all to get a copy of it. A guest holding a tracking link
 * was worse off still: the document download requires an account, so for them
 * the PDF may as well not have been generated.
 *
 * This renders the same figures as real HTML with a print stylesheet, so
 * "print" and "save as PDF" both work in any browser, including on a phone,
 * with no account and no signed URL to expire.
 *
 * Authorisation is not relaxed anywhere. The signed-in routes go through the
 * same policies as the screens they are reached from, and the guest routes
 * accept only the same unguessable token that already grants sight of the
 * document — printing shows nothing that reading it did not.
 */
class PrintableDocumentController extends Controller
{
    public function __construct(private readonly SettingsRepository $settings) {}

    public function invoice(Invoice $invoice): View
    {
        $this->authorize('view', $invoice);

        return $this->renderInvoice($invoice, $this->invoiceBack($invoice));
    }

    public function quotation(Quotation $quotation): View
    {
        $this->authorize('view', $quotation);

        return $this->renderQuotation($quotation, $this->quotationBack($quotation));
    }

    /** A guest copy, by the same token that shows the document itself. */
    public function trackedInvoice(string $token): View
    {
        $invoice = Invoice::query()
            ->where('tracking_token', $this->validToken($token))
            ->visibleToCustomer()
            ->with(['items', 'quotation:id,number'])
            ->firstOrFail();

        return $this->renderInvoice($invoice, route('invoices.track', $token));
    }

    public function trackedQuotation(string $token): View
    {
        $quotation = Quotation::query()
            ->where('tracking_token', $this->validToken($token))
            ->visibleToCustomer()
            ->with('items')
            ->firstOrFail();

        return $this->renderQuotation($quotation, route('quotations.track', $token));
    }

    private function renderInvoice(Invoice $invoice, string $back): View
    {
        return view('billing.invoices.print', [
            'invoice' => $invoice->loadMissing(['items', 'quotation:id,number']),
            'brand' => $this->settings->brand(),
            'back' => $back,
        ]);
    }

    private function renderQuotation(Quotation $quotation, string $back): View
    {
        return view('billing.quotations.print', [
            'quotation' => $quotation->loadMissing('items'),
            'brand' => $this->settings->brand(),
            'back' => $back,
        ]);
    }

    /**
     * Shape-checked before it reaches the database.
     *
     * A token is sixty-four hex characters and nothing else, so anything of a
     * different shape is a 404 rather than a query — the same rule the track
     * routes already apply.
     */
    private function validToken(string $token): string
    {
        abort_unless(preg_match('/\A[0-9a-f]{64}\z/', $token) === 1, 404);

        return $token;
    }

    /** Back to wherever this reader came from, by their role. */
    private function invoiceBack(Invoice $invoice): string
    {
        return request()->user()?->canAccessAdministration()
            ? route('admin.invoices.show', $invoice)
            : route('portal.invoices.show', $invoice);
    }

    private function quotationBack(Quotation $quotation): string
    {
        return request()->user()?->canAccessAdministration()
            ? route('admin.quotations.show', $quotation)
            : route('portal.quotations.show', $quotation);
    }
}
