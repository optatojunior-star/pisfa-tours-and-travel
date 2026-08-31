<?php

namespace App\Http\Controllers\Leasing;

use App\Actions\Leasing\SubmitLeaseApplication;
use App\Enums\LeasePayoutModel;
use App\Http\Controllers\Controller;
use App\Http\Requests\Leasing\StoreLeaseApplicationRequest;
use App\Models\VehicleLease;
use App\Models\VehicleLeaseApplication;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * The public "lease your car to PISFA" surface.
 *
 * Open to guests: the form is the first contact PISFA has with most owners, and
 * demanding a registration before it would lose the lead.
 */
class LeaseApplicationController extends Controller
{
    public function create(Request $request): View
    {
        return view('leasing.create', [
            'idempotencyKey' => (string) Str::uuid(),
            'payoutModels' => LeasePayoutModel::cases(),
            'user' => $request->user(),
        ]);
    }

    public function store(StoreLeaseApplicationRequest $request, SubmitLeaseApplication $action): RedirectResponse
    {
        $application = $action->execute(
            $request->user(),
            $request->validated(),
            (string) $request->validated('idempotency_key'),
        );

        return redirect()
            ->route('leasing.show', $application->reference)
            ->with('success', 'Thank you. Your reference is '.$application->reference
                .'. We will be in touch to arrange an inspection.');
    }

    /**
     * Progress for one offer, found by its reference.
     *
     * A guest has no account to sign in to, so the reference — which only
     * reaches the person who submitted the form, by email — is what opens it.
     * A signed-in owner still only sees their own.
     */
    public function show(Request $request, string $reference): View
    {
        $application = VehicleLeaseApplication::query()
            ->where('reference', $reference)
            ->firstOrFail();

        $user = $request->user();

        // An offer that belongs to an account is not readable by reference
        // alone: whoever holds it must be signed in as that owner, or be staff.
        if ($application->owner_id !== null) {
            abort_unless($user !== null && $user->can('view', $application), 404);
        }

        return view('leasing.show', [
            'application' => $application->load('lease'),
            'lease' => $application->lease,
        ]);
    }

    /** An owner's own lease, in the portal. */
    public function lease(VehicleLease $ownerLease): View
    {
        $this->authorize('view', $ownerLease);

        return view('portal.leases.show', [
            'lease' => $ownerLease->load(['vehicle', 'application']),
            // Drafts are internal working and never reach the owner.
            'payouts' => $ownerLease->payouts()->visibleToOwner()->get(),
        ]);
    }
}
