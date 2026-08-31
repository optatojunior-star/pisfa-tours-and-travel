<?php

namespace App\Http\Controllers\Tours;

use App\Actions\Tours\CreateTourBooking;
use App\Enums\TourBookingStatus;
use App\Enums\TourDepartureStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tours\StoreTourBookingRequest;
use App\Models\TourBooking;
use App\Models\TourDeparture;
use App\Models\TourPackage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class TourBookingController extends Controller
{
    public function create(Request $request, TourPackage $tourPackage): View
    {
        $this->authorize('create', TourBooking::class);

        $package = TourPackage::query()
            ->published()
            ->whereKey($tourPackage->getKey())
            ->with(['category', 'coverMedia'])
            ->firstOrFail();

        $departureId = filter_var($request->query('departure'), FILTER_VALIDATE_INT);
        abort_if($departureId === false || $departureId === null, 404);

        $departure = $package->departures()
            ->whereKey($departureId)
            ->where('status', TourDepartureStatus::Scheduled->value)
            ->where('starts_at', '>', now())
            ->where('cancellation_cutoff_at', '>', now())
            ->withSum([
                'bookings as reserved_seats' => fn ($booking) => $booking
                    ->whereIn('status', TourBookingStatus::capacityHoldingValues()),
            ], 'traveler_count')
            ->firstOrFail();

        $remainingSeats = $departure->capacity - (int) ($departure->reserved_seats ?? 0);

        abort_if(
            $remainingSeats < $package->min_travelers,
            409,
            'This departure no longer has enough places for the minimum booking group.',
        );

        $idempotencyKey = (string) Str::uuid();

        return view('tour-bookings.create', compact('package', 'departure', 'idempotencyKey'));
    }

    public function store(
        StoreTourBookingRequest $request,
        TourPackage $tourPackage,
        CreateTourBooking $createTourBooking,
    ): RedirectResponse {
        $package = TourPackage::query()
            ->published()
            ->whereKey($tourPackage->getKey())
            ->firstOrFail();

        $validated = $request->validated();
        $departure = TourDeparture::query()
            ->whereKey($validated['departure_id'])
            ->where('tour_package_id', $package->getKey())
            ->firstOrFail();

        $booking = $createTourBooking->execute(
            customer: $request->user(),
            departure: $departure,
            attributes: $validated,
            idempotencyKey: $validated['idempotency_key'],
        );

        return redirect()
            ->route('portal.bookings.show', $booking)
            ->with(
                'success',
                'Booking request received. No payment has been taken. PISFA will confirm availability and send payment instructions separately.',
            );
    }
}
