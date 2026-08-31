<?php

namespace App\Http\Controllers\Accommodation;

use App\Actions\Accommodation\CreatePropertyBooking;
use App\Actions\Accommodation\TransitionPropertyBooking;
use App\Http\Controllers\Controller;
use App\Http\Requests\Accommodation\StorePropertyBookingRequest;
use App\Models\Property;
use App\Models\PropertyBooking;
use App\Models\PropertyRoomType;
use App\Support\Payments\PayableRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PropertyBookingController extends Controller
{
    public function store(
        StorePropertyBookingRequest $request,
        string $property,
        CreatePropertyBooking $action,
    ): RedirectResponse {
        $found = Property::query()
            ->published()
            ->where('slug', $property)
            ->firstOrFail();

        $roomType = PropertyRoomType::query()
            ->whereKey((int) $request->validated('property_room_type_id'))
            ->firstOrFail();

        // A room from another property is a 404 rather than a 403: the request
        // names a resource that does not exist at this address.
        abort_unless((int) $roomType->property_id === (int) $found->getKey(), 404);

        $booking = $action->execute(
            $request->user(),
            $found,
            $roomType,
            $request->validated(),
            (string) $request->validated('idempotency_key'),
        );

        return redirect()
            ->route('portal.property-bookings.show', ['customerPropertyBooking' => $booking->reference])
            ->with('success', 'Request received. Your reference is '.$booking->reference
                .'. We hold the rooms while we confirm with the property.');
    }

    public function show(PropertyBooking $customerPropertyBooking): View
    {
        $this->authorize('view', $customerPropertyBooking);

        $customerPropertyBooking->load(['property.media', 'roomType']);

        return view('portal.stays.show', [
            'booking' => $customerPropertyBooking,
            'checkoutUrl' => $customerPropertyBooking->acceptsPayment()
                && $customerPropertyBooking->outstandingAmountMinor() > 0
                    ? PayableRegistry::checkoutUrl($customerPropertyBooking)
                    : null,
        ]);
    }

    public function cancel(
        Request $request,
        PropertyBooking $customerPropertyBooking,
        TransitionPropertyBooking $action,
    ): RedirectResponse {
        $this->authorize('cancelAsCustomer', $customerPropertyBooking);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ]);

        $action->cancel(
            $request->user(),
            $customerPropertyBooking,
            (string) $validated['reason'],
            byCustomer: true,
        );

        return back()->with('success', 'Your stay has been cancelled.');
    }
}
