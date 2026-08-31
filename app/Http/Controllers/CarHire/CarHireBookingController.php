<?php

namespace App\Http\Controllers\CarHire;

use App\Actions\CarHire\CreateCarHireBooking;
use App\Http\Controllers\Controller;
use App\Http\Requests\CarHire\CarHireCatalogueRequest;
use App\Http\Requests\CarHire\StoreCarHireBookingRequest;
use App\Models\CarHireBooking;
use App\Models\Vehicle;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Illuminate\View\View;

class CarHireBookingController extends Controller
{
    public function create(CarHireCatalogueRequest $request, Vehicle $vehicle): View
    {
        $this->authorize('create', CarHireBooking::class);

        $vehicle = Vehicle::query()
            ->acceptingHire()
            ->whereKey($vehicle->getKey())
            ->with(['media', 'hireRates' => fn (Builder|Relation $rates) => $rates
                ->active()
                ->effectiveAt(now())
                ->latest('effective_from')])
            ->firstOrFail();

        $filters = $request->validated();
        $idempotencyKey = (string) Str::uuid();
        $minimumPickup = CarbonImmutable::now(config('pisfa.business_timezone', 'Africa/Kampala'))
            ->addHours((int) config('car_hire.minimum_notice_hours', 2));

        return view('car-hire-bookings.create', compact('vehicle', 'filters', 'idempotencyKey', 'minimumPickup'));
    }

    public function store(StoreCarHireBookingRequest $request, Vehicle $vehicle, CreateCarHireBooking $action): RedirectResponse
    {
        $vehicle = Vehicle::query()->acceptingHire()->whereKey($vehicle->getKey())->firstOrFail();
        $validated = $request->validated();
        $booking = $action->execute($request->user(), $vehicle, $validated, $validated['idempotency_key']);

        return redirect()->route('portal.car-hire-bookings.show', $booking)->with(
            'success',
            'Hire request received. No payment has been taken. PISFA will review availability, documents where applicable, and contact you with the next steps.',
        );
    }
}
