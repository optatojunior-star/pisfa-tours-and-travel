<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Accommodation\TransitionPropertyBooking;
use App\Enums\PropertyBookingStatus;
use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\PropertyBooking;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PropertyBookingController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', PropertyBooking::class);

        $statusInput = $request->query('status');
        $status = is_string($statusInput) ? PropertyBookingStatus::tryFrom($statusInput) : null;

        $query = PropertyBooking::query()
            ->with(['property:id,slug,name', 'roomType:id,name', 'customer:id,name,email'])
            ->orderBy('check_in_date')
            ->orderBy('id');

        if ($status !== null) {
            $query->where('status', $status->value);
        } elseif ($request->query('show') !== 'all') {
            // The desk opens on stays that still need something done to them.
            $query->whereIn('status', PropertyBookingStatus::inventoryHoldingValues());
        }

        if (filled($request->query('q'))) {
            $query->search((string) $request->query('q'));
        }

        if (filled($request->query('property'))) {
            $query->whereHas('property', fn ($property) => $property->where('slug', (string) $request->query('property')));
        }

        return view('admin.accommodation.bookings.index', [
            'bookings' => $query->paginate(20)->withQueryString(),
            'status' => $status,
            'search' => $request->query('q'),
            'showAll' => $request->query('show') === 'all',
            'properties' => Property::query()->orderBy('name')->get(['id', 'slug', 'name']),
            'propertySlug' => $request->query('property'),
            'counts' => $this->counts(),
        ]);
    }

    public function show(PropertyBooking $booking): View
    {
        $this->authorize('view', $booking);

        $booking->load(['property', 'roomType', 'customer:id,name,email', 'rate', 'events']);

        // What the room type looks like without this booking in the count,
        // which is the number that decides whether it can be confirmed.
        $availableExcludingThis = $booking->roomType?->availableRooms(
            $booking->check_in_date,
            $booking->check_out_date,
            $booking->getKey(),
        );

        return view('admin.accommodation.bookings.show', [
            'booking' => $booking,
            'availableExcludingThis' => $availableExcludingThis,
            'nextStatuses' => $booking->status->allowedTransitions(),
        ]);
    }

    public function confirm(
        Request $request,
        PropertyBooking $booking,
        TransitionPropertyBooking $action,
    ): RedirectResponse {
        $this->authorize('manage', $booking);

        $action->confirm($request->user(), $booking);

        return back()->with('success', 'Confirmed. The guest has been told.');
    }

    public function decline(
        Request $request,
        PropertyBooking $booking,
        TransitionPropertyBooking $action,
    ): RedirectResponse {
        $this->authorize('manage', $booking);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ]);

        $action->decline($request->user(), $booking, (string) $validated['reason']);

        return back()->with('success', 'Declined, and the guest has been told why.');
    }

    public function cancel(
        Request $request,
        PropertyBooking $booking,
        TransitionPropertyBooking $action,
    ): RedirectResponse {
        $this->authorize('manage', $booking);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ]);

        $action->cancel($request->user(), $booking, (string) $validated['reason']);

        return back()->with('success', 'Cancelled. The rooms are free again.');
    }

    public function checkIn(
        Request $request,
        PropertyBooking $booking,
        TransitionPropertyBooking $action,
    ): RedirectResponse {
        $this->authorize('manage', $booking);

        $action->checkIn($request->user(), $booking);

        return back()->with('success', 'Checked in.');
    }

    public function checkOut(
        Request $request,
        PropertyBooking $booking,
        TransitionPropertyBooking $action,
    ): RedirectResponse {
        $this->authorize('manage', $booking);

        $action->checkOut($request->user(), $booking);

        return back()->with('success', 'Checked out. The rooms are free again.');
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        $counts = [];

        foreach (PropertyBookingStatus::cases() as $case) {
            $counts[$case->value] = PropertyBooking::query()->where('status', $case->value)->count();
        }

        $counts['arriving_today'] = PropertyBooking::query()
            ->whereIn('status', [
                PropertyBookingStatus::Confirmed->value,
                PropertyBookingStatus::Pending->value,
            ])
            ->whereDate('check_in_date', now()->toDateString())
            ->count();

        return $counts;
    }
}
