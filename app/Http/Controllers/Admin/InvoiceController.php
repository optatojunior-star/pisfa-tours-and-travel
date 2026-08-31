<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Billing\TransitionInvoice;
use App\Enums\InvoiceStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\CloseBillingDocumentRequest;
use App\Models\Invoice;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class InvoiceController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Invoice::class);

        $statusInput = $request->query('status');
        $status = is_string($statusInput) ? InvoiceStatus::tryFrom($statusInput) : null;
        $bucket = $request->query('bucket');

        $query = Invoice::query()
            ->with(['customer:id,name,email', 'quotation:id,number'])
            ->latest('id');

        if ($status !== null) {
            $query->where('status', $status->value);
        }

        if ($bucket === 'overdue') {
            $query->overdue();
        }

        if (filled($request->query('q'))) {
            $query->search((string) $request->query('q'));
        }

        return view('admin.invoices.index', [
            'invoices' => $query->paginate(20)->withQueryString(),
            'status' => $status,
            'bucket' => is_string($bucket) ? $bucket : null,
            'search' => $request->query('q'),
            'counts' => [
                'draft' => Invoice::query()->where('status', InvoiceStatus::Draft->value)->count(),
                'outstanding' => Invoice::query()->outstanding()->count(),
                'overdue' => Invoice::query()->overdue()->count(),
                'paid' => Invoice::query()->where('status', InvoiceStatus::Paid->value)->count(),
            ],
            'receivables' => $this->receivables(),
        ]);
    }

    public function show(Invoice $invoice): View
    {
        $this->authorize('view', $invoice);

        return view('admin.invoices.show', [
            'invoice' => $invoice->load([
                'items',
                'customer:id,name,email',
                'createdBy:id,name',
                'quotation:id,number,status',
                'payments',
            ]),
            'document' => $invoice->currentDocument(),
        ]);
    }

    public function issue(Request $request, Invoice $invoice, TransitionInvoice $action): RedirectResponse
    {
        $this->authorize('issue', $invoice);

        $action->issue($request->user(), $invoice);

        return back()->with('success', 'The invoice was issued and a PDF was filed against it.');
    }

    public function cancel(
        CloseBillingDocumentRequest $request,
        Invoice $invoice,
        TransitionInvoice $action,
    ): RedirectResponse {
        $action->cancel($request->user(), $invoice, (string) $request->validated('reason'));

        return back()->with('success', 'The invoice was cancelled.');
    }

    public function void(
        CloseBillingDocumentRequest $request,
        Invoice $invoice,
        TransitionInvoice $action,
    ): RedirectResponse {
        $action->void($request->user(), $invoice, (string) $request->validated('reason'));

        return back()->with('success', 'The invoice was voided. The correction is recorded in the audit log.');
    }

    /**
     * Outstanding receivables per currency.
     *
     * Grouped by currency and summed in PHP rather than converted in SQL: the
     * UGX and USD exponents differ, so adding the raw minor units together
     * would misstate the total by a factor of a hundred.
     *
     * @return array<string, array{outstanding_minor: int, overdue_minor: int, count: int}>
     */
    private function receivables(): array
    {
        $totals = [];

        Invoice::query()
            ->outstanding()
            ->with('paymentAllocations.payment:id,status')
            ->chunkById(200, function ($invoices) use (&$totals): void {
                foreach ($invoices as $invoice) {
                    $currency = $invoice->currency;
                    $outstanding = $invoice->outstandingAmountMinor();

                    $totals[$currency] ??= [
                        'outstanding_minor' => 0,
                        'overdue_minor' => 0,
                        'count' => 0,
                    ];

                    $totals[$currency]['outstanding_minor'] += $outstanding;
                    $totals[$currency]['count']++;

                    if ($invoice->isOverdue()) {
                        $totals[$currency]['overdue_minor'] += $outstanding;
                    }
                }
            });

        ksort($totals);

        return $totals;
    }

    /** Formats a receivables row for display. */
    public static function formatReceivable(int $minor, string $currency): string
    {
        return Money::format($minor, $currency);
    }
}
