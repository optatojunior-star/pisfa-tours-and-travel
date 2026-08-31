<?php

namespace App\Http\Controllers\Tours;

use App\Actions\Tours\CancelTourBooking;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tours\CancelTourBookingRequest;
use App\Http\Requests\Tours\IndexCustomerTourBookingsRequest;
use App\Models\TourBooking;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class BookingPortalController extends Controller
{
    public function index(IndexCustomerTourBookingsRequest $request): View
    {
        $filters = $request->validated();
        $query = TourBooking::query()
            ->forCustomer($request->user())
            ->with(['tourPackage.coverMedia', 'departure'])
            ->latest('departure_starts_at_snapshot')
            ->latest('id');

        if (filled($filters['q'] ?? null)) {
            $search = trim((string) $filters['q']);
            $query->where(function (Builder $nested) use ($search): void {
                $nested
                    ->where('reference', 'like', '%'.$search.'%')
                    ->orWhere('package_name_snapshot', 'like', '%'.$search.'%')
                    ->orWhere('destination_snapshot', 'like', '%'.$search.'%');
            });
        }

        if (filled($filters['status'] ?? null)) {
            $query->where('status', $filters['status']);
        }

        match ($filters['period'] ?? 'all') {
            'upcoming' => $query->where('departure_starts_at_snapshot', '>=', now()),
            'past' => $query->where('departure_starts_at_snapshot', '<', now()),
            default => null,
        };

        if (filled($filters['from'] ?? null)) {
            $from = CarbonImmutable::parse(
                $filters['from'],
                config('pisfa.business_timezone', 'Africa/Kampala'),
            )->startOfDay()->utc();
            $query->where('departure_starts_at_snapshot', '>=', $from);
        }

        if (filled($filters['to'] ?? null)) {
            $until = CarbonImmutable::parse(
                $filters['to'],
                config('pisfa.business_timezone', 'Africa/Kampala'),
            )->addDay()->startOfDay()->utc();
            $query->where('departure_starts_at_snapshot', '<', $until);
        }

        $bookings = $query->paginate(15)->withQueryString();

        return view('tour-bookings.index', compact('bookings', 'filters'));
    }

    public function show(TourBooking $customerTourBooking): View
    {
        $this->authorize('view', $customerTourBooking);

        $booking = $customerTourBooking->load([
            'tourPackage.coverMedia',
            'departure',
            'travelers',
            'assignedDriver:id,name,phone',
        ]);
        $canCancel = $booking->canBeCancelledAt(now());
        $cancellationDeadline = $booking->cancellation_cutoff_at_snapshot;

        return view('tour-bookings.show', compact('booking', 'canCancel', 'cancellationDeadline'));
    }

    public function cancel(
        CancelTourBookingRequest $request,
        TourBooking $customerTourBooking,
        CancelTourBooking $cancelTourBooking,
    ): RedirectResponse {
        $this->authorize('cancel', $customerTourBooking);

        $booking = $cancelTourBooking->execute(
            actor: $request->user(),
            booking: $customerTourBooking,
            reason: $request->string('reason')->toString(),
        );

        return redirect()
            ->route('portal.bookings.show', $booking)
            ->with('success', 'Your booking was cancelled. The reserved places are available again.');
    }
}
