<?php

namespace App\Http\Controllers\Corporate;

use App\Actions\Corporate\ManageGroupManifest;
use App\Actions\Corporate\SaveGroupBooking;
use App\Actions\Corporate\TransitionGroupBooking;
use App\Enums\GroupBookingStatus;
use App\Enums\TourTravelerType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Corporate\SaveGroupBookingRequest;
use App\Models\CorporateAccount;
use App\Models\CorporateMember;
use App\Models\GroupBooking;
use App\Models\GroupTraveler;
use App\Support\ServiceCatalogue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * A customer's own group trips.
 *
 * Open to any signed-in customer, not only corporate members: a school trip or
 * a family reunion is a group without being a company.
 */
class GroupBookingController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();

        // Their own groups, plus anything on a company they are live on. A
        // colleague has to be able to check the list when the person who booked
        // it is away.
        $accountIds = CorporateMember::query()
            ->where('user_id', $user->getKey())
            ->where('is_active', true)
            ->pluck('corporate_account_id');

        $bookings = GroupBooking::query()
            ->with(['account:id,slug,name', 'organiser:id,name'])
            ->withCount('travelers')
            ->where(function ($query) use ($user, $accountIds): void {
                $query->where('organiser_id', $user->getKey())
                    ->orWhereIn('corporate_account_id', $accountIds->all());
            })
            ->orderByDesc('starts_on')
            ->paginate(20);

        return view('portal.corporate.index', [
            'bookings' => $bookings,
            'accounts' => $this->bookableAccounts($request),
            'services' => ServiceCatalogue::keys(),
        ]);
    }

    public function store(SaveGroupBookingRequest $request, SaveGroupBooking $action): RedirectResponse
    {
        $accountId = $request->validated('corporate_account_id');

        $account = $accountId === null
            ? null
            : CorporateAccount::query()->whereKey((int) $accountId)->firstOrFail();

        $booking = $action->create($request->user(), $request->validated(), $account);

        return redirect()
            ->route('portal.groups.show', $booking)
            ->with('success', 'Group enquiry raised — reference '.$booking->reference
                .'. We will come back to you with a price.');
    }

    public function show(GroupBooking $group): View
    {
        $this->authorize('view', $group);

        return view('portal.corporate.show', [
            'booking' => $group->load(['account', 'organiser:id,name', 'travelers']),
            'travelerTypes' => TourTravelerType::cases(),
            'services' => ServiceCatalogue::keys(),
        ]);
    }

    public function update(
        SaveGroupBookingRequest $request,
        GroupBooking $group,
        SaveGroupBooking $action,
    ): RedirectResponse {
        $action->update($request->user(), $group, $request->validated());

        return back()->with('success', 'Group updated.');
    }

    public function addTraveler(
        Request $request,
        GroupBooking $group,
        ManageGroupManifest $action,
    ): RedirectResponse {
        $this->authorize('manageManifest', $group);

        $action->add($request->user(), $group, $request->all());

        return back()->with('success', 'Added to the list. '
            .$group->fresh()->manifestShortfall().' still to go.');
    }

    public function updateTraveler(
        Request $request,
        GroupBooking $group,
        GroupTraveler $traveler,
        ManageGroupManifest $action,
    ): RedirectResponse {
        $this->authorize('manageManifest', $group);

        $action->update($request->user(), $group, $traveler, $request->all());

        return back()->with('success', 'Traveller updated.');
    }

    public function removeTraveler(
        Request $request,
        GroupBooking $group,
        GroupTraveler $traveler,
        ManageGroupManifest $action,
    ): RedirectResponse {
        $this->authorize('manageManifest', $group);

        $action->remove($request->user(), $group, $traveler);

        return back()->with('success', 'Removed from the list.');
    }

    /** The organiser withdrawing their own enquiry. */
    public function cancel(
        Request $request,
        GroupBooking $group,
        TransitionGroupBooking $action,
    ): RedirectResponse {
        $this->authorize('update', $group);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ]);

        // A confirmed trip is PISFA's commitment as much as the customer's, so
        // withdrawing one is a conversation rather than a button.
        abort_unless(
            in_array($group->status, [
                GroupBookingStatus::Enquiry,
                GroupBookingStatus::Quoted,
                GroupBookingStatus::ManifestPending,
            ], true),
            403,
        );

        $action->cancel($request->user(), $group, (string) $validated['reason']);

        return back()->with('success', 'Your group enquiry has been withdrawn.');
    }

    /** @return Collection<int, CorporateAccount> */
    private function bookableAccounts(Request $request): Collection
    {
        $memberships = CorporateMember::query()
            ->with('account')
            ->where('user_id', $request->user()->getKey())
            ->where('is_active', true)
            ->get();

        return $memberships
            ->filter(fn (CorporateMember $member): bool => $member->canBook()
                && ($member->account?->status->canTrade() ?? false))
            ->map(fn (CorporateMember $member): CorporateAccount => $member->account)
            ->values();
    }
}
