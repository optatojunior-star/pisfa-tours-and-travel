<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Finance\RecoverExpensesOnPayout;
use App\Actions\Leasing\CalculateLeasePayout;
use App\Actions\Leasing\SaveVehicleLease;
use App\Actions\Leasing\TransitionLeasePayout;
use App\Actions\Leasing\TransitionVehicleLease;
use App\Enums\LeasePayoutModel;
use App\Enums\LeasePayoutStatus;
use App\Enums\LeaseStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveVehicleLeaseRequest;
use App\Models\User;
use App\Models\VehicleLease;
use App\Models\VehicleLeaseApplication;
use App\Models\VehicleLeasePayout;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class VehicleLeaseController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', VehicleLease::class);

        $statusInput = $request->query('status');
        $status = is_string($statusInput) ? LeaseStatus::tryFrom($statusInput) : null;

        $query = VehicleLease::query()
            ->with(['owner:id,name,email', 'vehicle:id,registration_plate,make,model'])
            ->withCount(['payouts as unpaid_payouts_count' => fn ($payouts) => $payouts->whereIn('status', [
                LeasePayoutStatus::Draft->value,
                LeasePayoutStatus::Approved->value,
            ])])
            ->latest('id');

        if ($status !== null) {
            $query->where('status', $status->value);
        }

        if (filled($request->query('q'))) {
            $query->search((string) $request->query('q'));
        }

        return view('admin.leasing.leases.index', [
            'leases' => $query->paginate(20)->withQueryString(),
            'status' => $status,
            'search' => $request->query('q'),
            'counts' => $this->counts(),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', VehicleLease::class);

        $reference = $request->query('application');

        $application = is_string($reference) && $reference !== ''
            ? VehicleLeaseApplication::query()->where('reference', $reference)->first()
            : null;

        return view('admin.leasing.leases.create', [
            'lease' => null,
            'application' => $application,
            'owners' => $this->owners(),
            'payoutModels' => LeasePayoutModel::cases(),
        ]);
    }

    public function store(SaveVehicleLeaseRequest $request, SaveVehicleLease $action): RedirectResponse
    {
        $owner = User::query()->whereKey((int) $request->validated('owner_id'))->firstOrFail();

        $applicationId = $request->validated('application_id');

        $application = $applicationId === null
            ? null
            : VehicleLeaseApplication::query()->whereKey((int) $applicationId)->firstOrFail();

        $lease = $action->create($request->user(), $owner, $request->validated(), $application);

        return redirect()
            ->route('admin.leasing.leases.show', $lease)
            ->with('success', 'Draft agreement saved. Activating it puts the vehicle into the fleet.');
    }

    public function show(VehicleLease $lease): View
    {
        $this->authorize('view', $lease);

        return view('admin.leasing.leases.show', [
            'lease' => $lease->load(['owner:id,name,email', 'vehicle', 'application', 'createdBy:id,name']),
            'payouts' => $lease->payouts()->with('approvedBy:id,name')->get(),
            'nextStatuses' => $lease->status->allowedTransitions(),
        ]);
    }

    public function edit(VehicleLease $lease): View
    {
        $this->authorize('update', $lease);

        return view('admin.leasing.leases.edit', [
            'lease' => $lease->load(['owner', 'application']),
            'application' => $lease->application,
            'owners' => $this->owners(),
            'payoutModels' => LeasePayoutModel::cases(),
        ]);
    }

    public function update(
        SaveVehicleLeaseRequest $request,
        VehicleLease $lease,
        SaveVehicleLease $action,
    ): RedirectResponse {
        $action->update($request->user(), $lease, $request->validated());

        return redirect()
            ->route('admin.leasing.leases.show', $lease)
            ->with('success', 'The terms were updated.');
    }

    public function activate(Request $request, VehicleLease $lease, TransitionVehicleLease $action): RedirectResponse
    {
        $this->authorize('commit', $lease);

        $activated = $action->activate($request->user(), $lease);

        return back()->with('success', 'Active. The vehicle is in the fleet as '
            // `??` already covers a missing relation, so the nullsafe operator
            // would only be noise here.
            .($activated->vehicle->registration_plate ?? 'a new record')
            .', in draft so it can be photographed and priced before it goes public.');
    }

    public function suspend(Request $request, VehicleLease $lease, TransitionVehicleLease $action): RedirectResponse
    {
        $this->authorize('suspend', $lease);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ]);

        $action->suspend($request->user(), $lease, (string) $validated['reason']);

        return back()->with('success', 'Suspended. The vehicle is off hire but the agreement stands.');
    }

    public function end(Request $request, VehicleLease $lease, TransitionVehicleLease $action): RedirectResponse
    {
        $this->authorize('commit', $lease);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ]);

        $action->end($request->user(), $lease, (string) $validated['reason']);

        return back()->with('success', 'Ended. The vehicle has been retired from the fleet.');
    }

    public function calculatePayout(
        Request $request,
        VehicleLease $lease,
        CalculateLeasePayout $action,
    ): RedirectResponse {
        $this->authorize('view', $lease);

        $validated = $request->validate([
            'month' => ['required', 'date'],
        ]);

        $payout = $action->forMonth($request->user(), $lease, (string) $validated['month']);

        return back()->with('success', 'Statement drawn up for '.$payout->monthLabel()
            .': '.$payout->formattedNet().' due.');
    }

    public function applyDeductions(
        Request $request,
        VehicleLease $lease,
        VehicleLeasePayout $payout,
        CalculateLeasePayout $action,
    ): RedirectResponse {
        $this->authorize('draft', $payout);

        abort_unless((int) $payout->vehicle_lease_id === (int) $lease->getKey(), 404);

        $validated = $request->validate([
            'deductions' => ['required', 'string', 'max:24'],
            'deductions_note' => ['nullable', 'string', 'max:255'],
        ]);

        $action->applyDeductions(
            $request->user(),
            $payout,
            (string) $validated['deductions'],
            $validated['deductions_note'] ?? null,
        );

        return back()->with('success', 'Deductions recorded.');
    }

    /**
     * Charges the vehicle's approved spending for the period to this statement.
     *
     * The alternative is somebody reading the expense console and typing the
     * figure into the deduction box, which is how the same repair gets charged
     * twice — or missed.
     */
    public function recoverExpenses(
        Request $request,
        VehicleLease $lease,
        VehicleLeasePayout $payout,
        RecoverExpensesOnPayout $action,
    ): RedirectResponse {
        $this->authorize('draft', $payout);

        abort_unless((int) $payout->vehicle_lease_id === (int) $lease->getKey(), 404);

        $updated = $action->execute($request->user(), $payout);

        return back()->with('success', 'Vehicle spending charged to the statement. '
            .$updated->formattedNet().' is now due to the owner.');
    }

    public function approvePayout(
        Request $request,
        VehicleLease $lease,
        VehicleLeasePayout $payout,
        TransitionLeasePayout $action,
    ): RedirectResponse {
        $this->authorize('settle', $payout);

        abort_unless((int) $payout->vehicle_lease_id === (int) $lease->getKey(), 404);

        $action->approve($request->user(), $payout);

        return back()->with('success', 'Approved. The owner has their statement.');
    }

    public function payPayout(
        Request $request,
        VehicleLease $lease,
        VehicleLeasePayout $payout,
        TransitionLeasePayout $action,
    ): RedirectResponse {
        $this->authorize('settle', $payout);

        abort_unless((int) $payout->vehicle_lease_id === (int) $lease->getKey(), 404);

        $validated = $request->validate([
            'payment_reference' => ['required', 'string', 'min:3', 'max:120'],
        ]);

        $action->markPaid($request->user(), $payout, (string) $validated['payment_reference']);

        return back()->with('success', 'Recorded as paid.');
    }

    public function reopenPayout(
        Request $request,
        VehicleLease $lease,
        VehicleLeasePayout $payout,
        TransitionLeasePayout $action,
        RecoverExpensesOnPayout $recovery,
    ): RedirectResponse {
        $this->authorize('settle', $payout);

        abort_unless((int) $payout->vehicle_lease_id === (int) $lease->getKey(), 404);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ]);

        $action->reopen($request->user(), $payout, (string) $validated['reason']);

        // Anything charged to this statement goes back in the pool. Left
        // marked, those expenses would be attached to a figure that no longer
        // exists and could never be recovered from anybody.
        $released = $recovery->release($request->user(), $payout);

        return back()->with('success', 'Sent back for recalculation.'
            .($released > 0 ? ' '.$released.' '.str('expense')->plural($released).' released for recovery again.' : ''));
    }

    /** @return Collection<int, User> */
    private function owners(): Collection
    {
        return User::query()
            ->where('role', UserRole::Customer->value)
            ->orderBy('name')
            ->limit(500)
            ->get(['id', 'name', 'email']);
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        $counts = [];

        foreach (LeaseStatus::cases() as $case) {
            $counts[$case->value] = VehicleLease::query()->where('status', $case->value)->count();
        }

        $counts['awaiting_payment'] = VehicleLeasePayout::query()
            ->where('status', LeasePayoutStatus::Approved->value)
            ->count();

        return $counts;
    }
}
