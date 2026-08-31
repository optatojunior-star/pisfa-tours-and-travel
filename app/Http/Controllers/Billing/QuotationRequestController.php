<?php

namespace App\Http\Controllers\Billing;

use App\Actions\Billing\SubmitQuotationRequest;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\StoreQuotationRequestRequest;
use App\Models\QuotationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class QuotationRequestController extends Controller
{
    public function store(
        StoreQuotationRequestRequest $request,
        SubmitQuotationRequest $action,
    ): RedirectResponse {
        $quotationRequest = $action->execute(
            $request->user(),
            $request->validated(),
            (string) $request->validated('idempotency_key'),
        );

        $message = 'Thank you. Your request reference is '.$quotationRequest->reference
            .'. We will price it and send you a written quotation.';

        if ($quotationRequest->isGuest()) {
            return redirect()
                ->route('quotation-requests.track', ['token' => $quotationRequest->tracking_token])
                ->with('success', $message);
        }

        return redirect()
            ->route('portal.quotation-requests.show', $quotationRequest)
            ->with('success', $message);
    }

    /**
     * Public tracking by unguessable token.
     *
     * The token is 32 bytes of CSPRNG output in a unique column, so it is the
     * credential. Its shape is checked before the database is touched, so a
     * short or absent token cannot become a broad scan.
     */
    public function track(string $token): View
    {
        abort_unless(preg_match('/\A[0-9a-f]{64}\z/', $token) === 1, 404);

        $quotationRequest = QuotationRequest::query()
            ->where('tracking_token', $token)
            ->with(['quotations' => fn ($query) => $query->visibleToCustomer()])
            ->firstOrFail();

        return view('billing.quotation-requests.track', [
            'request' => $quotationRequest,
            'quotations' => $quotationRequest->quotations,
            'isGuestView' => true,
        ]);
    }

    public function index(Request $request): View
    {
        return view('billing.quotation-requests.index', [
            'requests' => QuotationRequest::query()
                ->forCustomer($request->user())
                ->withCount('quotations')
                ->latest('id')
                ->paginate(10),
        ]);
    }

    public function show(QuotationRequest $customerQuotationRequest): View
    {
        $this->authorize('view', $customerQuotationRequest);

        return view('billing.quotation-requests.show', [
            'request' => $customerQuotationRequest,
            'quotations' => $customerQuotationRequest->quotations()->visibleToCustomer()->get(),
            'isGuestView' => false,
        ]);
    }
}
