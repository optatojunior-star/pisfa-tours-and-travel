<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\View\View;

class InvoiceController extends Controller
{
    public function index(Request $request): View
    {
        $invoices = Invoice::query()
            ->forCustomer($request->user())
            ->visibleToCustomer()
            ->latest('id')
            ->paginate(10);

        return view('billing.invoices.index', [
            'invoices' => $invoices,
        ]);
    }

    public function show(Invoice $customerInvoice): View
    {
        $this->authorize('view', $customerInvoice);

        return view('billing.invoices.show', [
            'invoice' => $customerInvoice->load(['items', 'quotation:id,number']),
            'isGuestView' => false,
        ]);
    }

    /** Public view of a guest invoice, by unguessable token. */
    public function track(string $token): View
    {
        abort_unless(preg_match('/\A[0-9a-f]{64}\z/', $token) === 1, 404);

        $invoice = Invoice::query()
            ->where('tracking_token', $token)
            ->visibleToCustomer()
            ->with(['items', 'quotation:id,number'])
            ->firstOrFail();

        return view('billing.invoices.track', [
            'invoice' => $invoice,
            'isGuestView' => true,
        ]);
    }
}
