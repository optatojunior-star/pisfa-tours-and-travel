<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Sales\TransitionSalesEnquiry;
use App\Enums\SalesEnquiryStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\VehicleSalesEnquiry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class SalesEnquiryController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', VehicleSalesEnquiry::class);

        $statusInput = $request->query('status');
        $status = is_string($statusInput) ? SalesEnquiryStatus::tryFrom($statusInput) : null;

        $query = VehicleSalesEnquiry::query()
            ->with(['listing:id,slug,title,status,currency,asking_price_minor', 'assignee:id,name'])
            ->latest('id');

        if ($status !== null) {
            $query->where('status', $status->value);
        } elseif ($request->query('show') !== 'all') {
            // The console opens on work that is still live; closed leads are a
            // deliberate choice, not the default view.
            $query->open();
        }

        if (filled($request->query('q'))) {
            $query->search((string) $request->query('q'));
        }

        if ($request->query('mine') === '1' && $request->user() !== null) {
            $query->where('assigned_to_user_id', $request->user()->getKey());
        }

        return view('admin.showroom.enquiries.index', [
            'enquiries' => $query->paginate(20)->withQueryString(),
            'status' => $status,
            'search' => $request->query('q'),
            'showAll' => $request->query('show') === 'all',
            'mine' => $request->query('mine') === '1',
            'counts' => $this->counts(),
        ]);
    }

    public function show(VehicleSalesEnquiry $enquiry): View
    {
        $this->authorize('view', $enquiry);

        return view('admin.showroom.enquiries.show', [
            'enquiry' => $enquiry->load(['listing.media', 'customer:id,name,email', 'assignee:id,name']),
            // Won is absent by design: a sale is recorded against the listing,
            // which marks the buyer's enquiry won and closes the rest.
            'nextStatuses' => array_values(array_filter(
                $enquiry->status->allowedTransitions(),
                static fn (SalesEnquiryStatus $next): bool => $next !== SalesEnquiryStatus::Won,
            )),
            'assignees' => $this->assignees(),
        ]);
    }

    public function advance(
        Request $request,
        VehicleSalesEnquiry $enquiry,
        TransitionSalesEnquiry $action,
    ): RedirectResponse {
        $this->authorize('manage', $enquiry);

        $validated = $request->validate([
            'status' => ['required', 'string'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $next = SalesEnquiryStatus::tryFrom((string) $validated['status']);

        abort_if($next === null, 404);

        $action->advance($request->user(), $enquiry, $next, $validated['note'] ?? null);

        return back()->with('success', 'Enquiry moved to '.mb_strtolower($next->label()).'.');
    }

    public function assign(
        Request $request,
        VehicleSalesEnquiry $enquiry,
        TransitionSalesEnquiry $action,
    ): RedirectResponse {
        $this->authorize('manage', $enquiry);

        $validated = $request->validate([
            'assigned_to_user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $assignee = isset($validated['assigned_to_user_id'])
            ? User::query()->whereKey((int) $validated['assigned_to_user_id'])->firstOrFail()
            : null;

        $action->assign($request->user(), $enquiry, $assignee);

        return back()->with('success', $assignee === null
            ? 'Unassigned.'
            : 'Assigned to '.$assignee->name.'.');
    }

    public function note(
        Request $request,
        VehicleSalesEnquiry $enquiry,
        TransitionSalesEnquiry $action,
    ): RedirectResponse {
        $this->authorize('manage', $enquiry);

        $validated = $request->validate([
            'note' => ['required', 'string', 'min:2', 'max:2000'],
        ]);

        $action->note($request->user(), $enquiry, (string) $validated['note']);

        return back()->with('success', 'Note added.');
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

        foreach (SalesEnquiryStatus::cases() as $case) {
            $counts[$case->value] = VehicleSalesEnquiry::query()
                ->where('status', $case->value)
                ->count();
        }

        $counts['unassigned'] = VehicleSalesEnquiry::query()
            ->open()
            ->whereNull('assigned_to_user_id')
            ->count();

        return $counts;
    }
}
