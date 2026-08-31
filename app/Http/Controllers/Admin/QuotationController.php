<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Billing\ConvertQuotationToInvoice;
use App\Actions\Billing\SaveQuotation;
use App\Actions\Billing\TransitionQuotation;
use App\Enums\QuotationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\CloseBillingDocumentRequest;
use App\Http\Requests\Billing\SaveQuotationRequest;
use App\Models\Quotation;
use App\Models\QuotationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class QuotationController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Quotation::class);

        $statusInput = $request->query('status');
        $status = is_string($statusInput) ? QuotationStatus::tryFrom($statusInput) : null;

        $query = Quotation::query()
            ->with(['customer:id,name,email', 'invoice:id,number,quotation_id'])
            ->latest('id');

        if ($status !== null) {
            $query->where('status', $status->value);
        }

        if (filled($request->query('q'))) {
            $query->search((string) $request->query('q'));
        }

        return view('admin.quotations.index', [
            'quotations' => $query->paginate(20)->withQueryString(),
            'status' => $status,
            'search' => $request->query('q'),
            'counts' => $this->counts(),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', Quotation::class);

        $sourceReference = $request->query('request');
        $source = is_string($sourceReference) && $sourceReference !== ''
            ? QuotationRequest::query()->where('reference', $sourceReference)->first()
            : null;

        return view('admin.quotations.create', [
            'quotation' => null,
            'source' => $source,
        ]);
    }

    public function store(SaveQuotationRequest $request, SaveQuotation $action): RedirectResponse
    {
        $sourceReference = $request->input('quotation_request_reference');
        $source = is_string($sourceReference) && $sourceReference !== ''
            ? QuotationRequest::query()->where('reference', $sourceReference)->first()
            : null;

        $quotation = $action->create($request->user(), $request->validated(), $source);

        return redirect()
            ->route('admin.quotations.show', $quotation)
            ->with('success', 'Quotation '.$quotation->number.' was drafted. Review it, then send it.');
    }

    public function show(Quotation $quotation): View
    {
        $this->authorize('view', $quotation);

        return view('admin.quotations.show', [
            'quotation' => $quotation->load([
                'items',
                'customer:id,name,email',
                'createdBy:id,name',
                'request:id,reference,service',
                'invoice',
            ]),
            'document' => $quotation->currentDocument(),
        ]);
    }

    public function edit(Quotation $quotation): View
    {
        $this->authorize('update', $quotation);

        return view('admin.quotations.edit', [
            'quotation' => $quotation->load('items'),
            'source' => $quotation->request,
        ]);
    }

    public function update(
        SaveQuotationRequest $request,
        Quotation $quotation,
        SaveQuotation $action,
    ): RedirectResponse {
        $action->update($request->user(), $quotation, $request->validated());

        return redirect()
            ->route('admin.quotations.show', $quotation)
            ->with('success', 'The draft was updated.');
    }

    public function send(Request $request, Quotation $quotation, TransitionQuotation $action): RedirectResponse
    {
        $this->authorize('send', $quotation);

        $action->send($request->user(), $quotation);

        return back()->with('success', 'The quotation was sent and a PDF was filed against it.');
    }

    public function revise(Request $request, Quotation $quotation, TransitionQuotation $action): RedirectResponse
    {
        $this->authorize('revise', $quotation);

        $revised = $action->revise($request->user(), $quotation);

        return redirect()
            ->route('admin.quotations.edit', $revised)
            ->with('success', 'Revision '.$revised->revision.' is open for editing.');
    }

    public function cancel(
        CloseBillingDocumentRequest $request,
        Quotation $quotation,
        TransitionQuotation $action,
    ): RedirectResponse {
        $action->cancel($request->user(), $quotation, (string) $request->validated('reason'));

        return back()->with('success', 'The quotation was cancelled.');
    }

    public function convert(
        Request $request,
        Quotation $quotation,
        ConvertQuotationToInvoice $action,
    ): RedirectResponse {
        $this->authorize('convert', $quotation);

        $invoice = $action->execute($request->user(), $quotation);

        return redirect()
            ->route('admin.invoices.show', $invoice)
            ->with('success', 'Draft invoice '.$invoice->number.' was created. Review it, then issue it.');
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        return [
            'draft' => Quotation::query()->where('status', QuotationStatus::Draft->value)->count(),
            'sent' => Quotation::query()->where('status', QuotationStatus::Sent->value)->count(),
            'accepted' => Quotation::query()->where('status', QuotationStatus::Accepted->value)->count(),
            'declined' => Quotation::query()->where('status', QuotationStatus::Declined->value)->count(),
        ];
    }
}
