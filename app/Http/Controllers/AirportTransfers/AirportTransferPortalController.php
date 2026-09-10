<?php

namespace App\Http\Controllers\AirportTransfers;

use App\Actions\AirportTransfers\CancelAirportTransferBooking;
use App\Http\Controllers\Controller;
use App\Http\Requests\AirportTransfers\CancelAirportTransferBookingRequest;
use App\Http\Requests\AirportTransfers\IndexCustomerAirportTransfersRequest;
use App\Models\AirportTransferBooking;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class AirportTransferPortalController extends Controller
{
    public function index(IndexCustomerAirportTransfersRequest $request): View
    {
        $filters = $request->validated();
        $timezone = (string) config('pisfa.business_timezone', 'Africa/Kampala');

        $query = AirportTransferBooking::query()
            ->forCustomer($request->user())
            ->with(['airport:id,code,name,city', 'location:id,slug,name,region', 'assignedDriver:id,name,phone'])
            ->latest('service_starts_at')
            ->latest('id');

        if (filled($filters['q'] ?? null)) {
            $search = trim((string) $filters['q']);
            $query->where(fn (Builder $nested) => $nested
                ->where('reference', 'like', '%'.$search.'%')
                ->orWhere('airport_name_snapshot', 'like', '%'.$search.'%')
                ->orWhere('airport_code_snapshot', 'like', '%'.$search.'%')
                ->orWhere('location_name_snapshot', 'like', '%'.$search.'%'));
        }

        if (filled($filters['status'] ?? null)) {
            $query->where('status', $filters['status']);
        }

        match ($filters['period'] ?? 'all') {
            'upcoming' => $query->where('service_starts_at', '>=', now()),
            'past' => $query->where('service_starts_at', '<', now()),
            default => null,
        };

        if (filled($filters['from'] ?? null)) {
            $query->where('service_starts_at', '>=', CarbonImmutable::parse($filters['from'], $timezone)
                ->startOfDay()
                ->utc());
        }

        if (filled($filters['to'] ?? null)) {
            $query->where('service_starts_at', '<', CarbonImmutable::parse($filters['to'], $timezone)
                ->addDay()
                ->startOfDay()
                ->utc());
        }

        $bookings = $query->paginate(15)->withQueryString();

        return view('airport-transfer-bookings.index', compact('bookings', 'filters'));
    }

    public function show(AirportTransferBooking $customerAirportTransferBooking): View
    {
        $this->authorize('view', $customerAirportTransferBooking);

        $booking = $customerAirportTransferBooking->load([
            'airport', 'location', 'rate',
            // The plate is what a customer checks in a car park, so it has to
            // be in the column list or the confirmation cannot show it.
            'assignedVehicle:id,slug,make,model,year,vehicle_type,seating_capacity,registration_plate',
            'assignedDriver:id,name,phone',
            'assignedDriver.driverProfile.photographs',
        ]);
        $canCancel = $booking->canBeCancelledAt();

        return view('airport-transfer-bookings.show', compact('booking', 'canCancel'));
    }

    public function cancel(
        CancelAirportTransferBookingRequest $request,
        AirportTransferBooking $customerAirportTransferBooking,
        CancelAirportTransferBooking $action,
    ): RedirectResponse {
        $booking = $action->execute(
            $request->user(),
            $customerAirportTransferBooking,
            $request->string('reason')->toString(),
        );

        return redirect()
            ->route('portal.airport-transfer-bookings.show', $booking)
            ->with('success', 'Your airport transfer was cancelled and any reserved team was released.');
    }
}
