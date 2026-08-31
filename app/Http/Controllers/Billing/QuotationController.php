<?php

namespace App\Http\Controllers\Billing;

use App\Actions\Billing\TransitionQuotation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\RespondToQuotationRequest;
use App\Models\Quotation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class QuotationController extends Controller
{
    public function index(Request $request): View
    {
        return view('billing.quotations.index', [
            'quotations' => Quotation::query()
                ->forCustomer($request->user())
                ->visibleToCustomer()
                ->with('invoice:id,number,quotation_id,status')
                ->latest('id')
                ->paginate(10),
        ]);
    }

    public function show(Quotation $customerQuotation): View
    {
        $this->authorize('view', $customerQuotation);

        return view('billing.quotations.show', [
            'quotation' => $customerQuotation->load(['items', 'invoice']),
            'isGuestView' => false,
        ]);
    }

    /** Accept or decline, as the signed-in customer the offer was made to. */
    public function respond(
        RespondToQuotationRequest $request,
        Quotation $customerQuotation,
        TransitionQuotation $action,
    ): RedirectResponse {
        $this->authorize('respond', $customerQuotation);

        $accepted = $request->accepted();

        $action->respond(
            $request->user(),
            $customerQuotation,
            $accepted,
            $request->validated('reason'),
        );

        return redirect()
            ->route('portal.quotations.show', $customerQuotation)
            ->with('success', $accepted
                ? 'Thank you. We will send an invoice shortly.'
                : 'Thank you for letting us know. We will be in touch.');
    }

    /** Public view of a guest quotation, by unguessable token. */
    public function track(string $token): View
    {
        abort_unless(preg_match('/\A[0-9a-f]{64}\z/', $token) === 1, 404);

        $quotation = Quotation::query()
            ->where('tracking_token', $token)
            ->visibleToCustomer()
            ->with(['items', 'invoice'])
            ->firstOrFail();

        return view('billing.quotations.track', [
            'quotation' => $quotation,
            'isGuestView' => true,
        ]);
    }

    /** Accept or decline through the guest token link. */
    public function respondAsGuest(
        RespondToQuotationRequest $request,
        string $token,
        TransitionQuotation $action,
    ): RedirectResponse {
        abort_unless(preg_match('/\A[0-9a-f]{64}\z/', $token) === 1, 404);

        $quotation = Quotation::query()
            ->where('tracking_token', $token)
            ->visibleToCustomer()
            ->firstOrFail();

        $accepted = $request->accepted();

        // The action refuses a guest response on a quotation that belongs to an
        // account, so holding the token is not enough to settle someone else's.
        $action->respond(null, $quotation, $accepted, $request->validated('reason'));

        return redirect()
            ->route('quotations.track', ['token' => $token])
            ->with('success', $accepted
                ? 'Thank you. We will send an invoice shortly.'
                : 'Thank you for letting us know. We will be in touch.');
    }
}
