<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Leasing\TransitionLeaseApplication;
use App\Enums\LeaseApplicationStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\VehicleLeaseApplication;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class LeaseApplicationController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', VehicleLeaseApplication::class);

        $statusInput = $request->query('status');
        $status = is_string($statusInput) ? LeaseApplicationStatus::tryFrom($statusInput) : null;

        $query = VehicleLeaseApplication::query()
            ->with(['owner:id,name', 'assignee:id,name', 'lease:id,application_id,reference,status'])
            ->latest('id');

        if ($status !== null) {
            $query->where('status', $status->value);
        } elseif ($request->query('show') !== 'all') {
            // The desk opens on offers that still need somebody to act.
            $query->open();
        }

        if (filled($request->query('q'))) {
            $query->search((string) $request->query('q'));
        }

        return view('admin.leasing.applications.index', [
            'applications' => $query->paginate(20)->withQueryString(),
            'status' => $status,
            'search' => $request->query('q'),
            'showAll' => $request->query('show') === 'all',
            'counts' => $this->counts(),
        ]);
    }

    public function show(VehicleLeaseApplication $application): View
    {
        $this->authorize('view', $application);

        return view('admin.leasing.applications.show', [
            'application' => $application->load(['owner:id,name,email', 'assignee:id,name', 'lease']),
            'nextStatuses' => $application->status->allowedTransitions(),
            'assignees' => $this->assignees(),
        ]);
    }

    public function review(
        Request $request,
        VehicleLeaseApplication $application,
        TransitionLeaseApplication $action,
    ): RedirectResponse {
        $this->authorize('manage', $application);

        $action->review($request->user(), $application);

        return back()->with('success', 'Moved to review.');
    }

    public function arrangeInspection(
        Request $request,
        VehicleLeaseApplication $application,
        TransitionLeaseApplication $action,
    ): RedirectResponse {
        $this->authorize('manage', $application);

        $validated = $request->validate([
            'inspection_at' => ['required', 'date'],
            'inspection_location' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        $action->arrangeInspection(
            $request->user(),
            $application,
            (string) $validated['inspection_at'],
            (string) $validated['inspection_location'],
        );

        return back()->with('success', 'Inspection arranged, and the owner has been told.');
    }

    public function recordInspection(
        Request $request,
        VehicleLeaseApplication $application,
        TransitionLeaseApplication $action,
    ): RedirectResponse {
        $this->authorize('manage', $application);

        $validated = $request->validate([
            'findings' => ['required', 'string', 'min:10', 'max:5000'],
        ]);

        $action->recordInspection($request->user(), $application, (string) $validated['findings']);

        return back()->with('success', 'Findings recorded. The offer can now be approved or declined.');
    }

    public function approve(
        Request $request,
        VehicleLeaseApplication $application,
        TransitionLeaseApplication $action,
    ): RedirectResponse {
        $this->authorize('approve', $application);

        $action->approve($request->user(), $application);

        return back()->with('success', 'Approved. Draw up the lease terms next.');
    }

    public function decline(
        Request $request,
        VehicleLeaseApplication $application,
        TransitionLeaseApplication $action,
    ): RedirectResponse {
        $this->authorize('manage', $application);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ]);

        $action->decline($request->user(), $application, (string) $validated['reason']);

        return back()->with('success', 'Declined, and the owner has been told why.');
    }

    public function withdraw(
        Request $request,
        VehicleLeaseApplication $application,
        TransitionLeaseApplication $action,
    ): RedirectResponse {
        $this->authorize('manage', $application);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ]);

        $action->withdraw($request->user(), $application, (string) $validated['reason']);

        return back()->with('success', 'Recorded as withdrawn by the owner.');
    }

    public function assign(
        Request $request,
        VehicleLeaseApplication $application,
        TransitionLeaseApplication $action,
    ): RedirectResponse {
        $this->authorize('manage', $application);

        $validated = $request->validate([
            'assigned_to_user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $assignee = isset($validated['assigned_to_user_id'])
            ? User::query()->whereKey((int) $validated['assigned_to_user_id'])->firstOrFail()
            : null;

        $action->assign($request->user(), $application, $assignee);

        return back()->with('success', $assignee === null ? 'Unassigned.' : 'Assigned to '.$assignee->name.'.');
    }

    /** @return Collection<int, User> */
    private function assignees(): Collection
    {
        return User::query()
            ->whereIn('role', [UserRole::Staff->value, UserRole::Manager->value, UserRole::SuperAdmin->value])
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        $counts = [];

        foreach (LeaseApplicationStatus::cases() as $case) {
            $counts[$case->value] = VehicleLeaseApplication::query()
                ->where('status', $case->value)
                ->count();
        }

        $counts['unassigned'] = VehicleLeaseApplication::query()
            ->open()
            ->whereNull('assigned_to_user_id')
            ->count();

        return $counts;
    }
}
