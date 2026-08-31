<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Corporate\SaveGroupBooking;
use App\Actions\Corporate\TransitionGroupBooking;
use App\Enums\GroupBookingStatus;
use App\Http\Controllers\Controller;
use App\Models\GroupBooking;
use App\Services\Corporate\CorporateCreditQuery;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;

class GroupBookingController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', GroupBooking::class);

        $statusInput = $request->query('status');
        $status = is_string($statusInput) ? GroupBookingStatus::tryFrom($statusInput) : null;

        $query = GroupBooking::query()
            ->with(['account:id,slug,name', 'organiser:id,name,email'])
            ->withCount('travelers')
            ->orderBy('starts_on');

        if ($status !== null) {
            $query->where('status', $status->value);
        } elseif ($request->query('show') !== 'all') {
            $query->open();
        }

        if (filled($request->query('q'))) {
            $query->search((string) $request->query('q'));
        }

        return view('admin.corporate.groups.index', [
            'bookings' => $query->paginate(20)->withQueryString(),
            'status' => $status,
            'search' => $request->query('q'),
            'showAll' => $request->query('show') === 'all',
            'counts' => $this->counts(),
        ]);
    }

    public function show(GroupBooking $group, CorporateCreditQuery $credit): View
    {
        $this->authorize('view', $group);

        $group->load(['account', 'organiser:id,name,email', 'travelers', 'quotation']);

        return view('admin.corporate.groups.show', [
            'booking' => $group,
            // Shown before confirming, so the desk sees the refusal coming
            // rather than discovering it when the button fails.
            'position' => $group->account === null ? null : $credit->position($group->account),
            'nextStatuses' => $group->status->allowedTransitions(),
        ]);
    }

    public function price(Request $request, GroupBooking $group, SaveGroupBooking $action): RedirectResponse
    {
        $this->authorize('transition', $group);

        $validated = $request->validate([
            'quoted_total' => ['required', 'string', 'max:24'],
        ]);

        try {
            $minor = Money::parse(trim((string) $validated['quoted_total']), $group->currency);
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['quoted_total' => $exception->getMessage()]);
        }

        $action->update($request->user(), $group, [
            'title' => $group->title,
            'service_kind' => $group->service_kind,
            'starts_on' => $group->starts_on->toDateString(),
            'ends_on' => $group->ends_on->toDateString(),
            'headcount' => $group->headcount,
            'pickup_location' => $group->pickup_location,
            'destination' => $group->destination,
            'requirements' => $group->requirements,
            'internal_notes' => $group->internal_notes,
            'quoted_total' => (string) $validated['quoted_total'],
            'currency' => $group->currency,
        ]);

        return back()->with('success', 'Priced at '.Money::format($minor, $group->currency).'.');
    }

    public function quote(Request $request, GroupBooking $group, TransitionGroupBooking $action): RedirectResponse
    {
        $this->authorize('transition', $group);

        $action->quote($request->user(), $group);

        return back()->with('success', 'Quoted, and the organiser has been told.');
    }

    public function requestManifest(Request $request, GroupBooking $group, TransitionGroupBooking $action): RedirectResponse
    {
        $this->authorize('transition', $group);

        $action->requestManifest($request->user(), $group);

        return back()->with('success', 'Asked the organiser for the traveller list.');
    }

    public function confirm(Request $request, GroupBooking $group, TransitionGroupBooking $action): RedirectResponse
    {
        $this->authorize('transition', $group);

        $action->confirm($request->user(), $group);

        return back()->with('success', 'Confirmed.');
    }

    public function start(Request $request, GroupBooking $group, TransitionGroupBooking $action): RedirectResponse
    {
        $this->authorize('transition', $group);

        $action->start($request->user(), $group);

        return back()->with('success', 'Marked as under way.');
    }

    public function complete(Request $request, GroupBooking $group, TransitionGroupBooking $action): RedirectResponse
    {
        $this->authorize('transition', $group);

        $action->complete($request->user(), $group);

        return back()->with('success', 'Completed.');
    }

    public function cancel(Request $request, GroupBooking $group, TransitionGroupBooking $action): RedirectResponse
    {
        $this->authorize('transition', $group);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ]);

        $action->cancel($request->user(), $group, (string) $validated['reason']);

        return back()->with('success', 'Cancelled, and the organiser has been told.');
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        $counts = [];

        foreach (GroupBookingStatus::cases() as $case) {
            $counts[$case->value] = GroupBooking::query()->where('status', $case->value)->count();
        }

        return $counts;
    }
}
