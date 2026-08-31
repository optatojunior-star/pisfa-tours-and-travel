<?php

namespace App\Http\Controllers\CarHire;

use App\Actions\CarHire\CancelCarHireBooking;
use App\Http\Controllers\Controller;
use App\Http\Requests\CarHire\CancelCarHireBookingRequest;
use App\Http\Requests\CarHire\IndexCustomerCarHireBookingsRequest;
use App\Models\CarHireBooking;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class CarHirePortalController extends Controller
{
    public function index(IndexCustomerCarHireBookingsRequest $request): View
    {
        $filters = $request->validated();
        $query = CarHireBooking::query()->forCustomer($request->user())
            ->with(['vehicle.coverMedia', 'selfDriveApplication', 'latestNonVoidedContract'])
            ->latest('pickup_at')->latest('id');

        if (filled($filters['q'] ?? null)) {
            $search = trim((string) $filters['q']);
            $query->where(fn (Builder $nested) => $nested
                ->where('reference', 'like', '%'.$search.'%')
                ->orWhere('vehicle_name_snapshot', 'like', '%'.$search.'%')
                ->orWhere('pickup_location', 'like', '%'.$search.'%'));
        }

        if (filled($filters['status'] ?? null)) {
            $query->where('status', $filters['status']);
        }

        match ($filters['period'] ?? 'all') {
            'upcoming' => $query->where('return_at', '>=', now()),
            'past' => $query->where('return_at', '<', now()),
            default => null,
        };

        if (filled($filters['from'] ?? null)) {
            $query->where('pickup_at', '>=', CarbonImmutable::parse($filters['from'], config('pisfa.business_timezone'))->startOfDay()->utc());
        }
        if (filled($filters['to'] ?? null)) {
            $query->where('pickup_at', '<', CarbonImmutable::parse($filters['to'], config('pisfa.business_timezone'))->addDay()->startOfDay()->utc());
        }

        $bookings = $query->paginate(15)->withQueryString();

        return view('car-hire-bookings.index', compact('bookings', 'filters'));
    }

    public function show(CarHireBooking $customerCarHireBooking): View
    {
        $this->authorize('view', $customerCarHireBooking);
        $booking = $customerCarHireBooking->load(['vehicle.media', 'hireRate', 'selfDriveApplication.reviewedBy:id,name', 'documents', 'contracts', 'latestNonVoidedContract', 'assignedDriver:id,name,phone']);
        $canCancel = $booking->canBeCancelledAt();

        return view('car-hire-bookings.show', compact('booking', 'canCancel'));
    }

    public function cancel(CancelCarHireBookingRequest $request, CarHireBooking $customerCarHireBooking, CancelCarHireBooking $action): RedirectResponse
    {
        $booking = $action->execute($request->user(), $customerCarHireBooking, $request->string('reason')->toString());

        return redirect()->route('portal.car-hire-bookings.show', $booking)
            ->with('success', 'Your car-hire request was cancelled and the vehicle hold was released.');
    }
}
