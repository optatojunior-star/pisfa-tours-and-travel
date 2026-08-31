<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Accommodation\SaveProperty;
use App\Actions\Accommodation\SaveRoomRate;
use App\Actions\Accommodation\SaveRoomType;
use App\Actions\Accommodation\TransitionProperty;
use App\Enums\PropertyBookingStatus;
use App\Enums\PropertyStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SavePropertyRequest;
use App\Models\Property;
use App\Models\PropertyBooking;
use App\Models\PropertyRoomRate;
use App\Models\PropertyRoomType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PropertyController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Property::class);

        $statusInput = $request->query('status');
        $status = is_string($statusInput) ? PropertyStatus::tryFrom($statusInput) : null;

        $query = Property::query()
            ->withCount([
                'roomTypes as active_room_types_count' => fn ($types) => $types->active(),
                'bookings as open_bookings_count' => fn ($bookings) => $bookings->whereIn(
                    'status',
                    PropertyBookingStatus::inventoryHoldingValues(),
                ),
            ])
            ->orderBy('name');

        if ($status !== null) {
            $query->where('status', $status->value);
        }

        if (filled($request->query('q'))) {
            $query->search((string) $request->query('q'));
        }

        return view('admin.accommodation.index', [
            'properties' => $query->paginate(20)->withQueryString(),
            'status' => $status,
            'search' => $request->query('q'),
            'counts' => $this->counts(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Property::class);

        return view('admin.accommodation.create', ['property' => null]);
    }

    public function store(SavePropertyRequest $request, SaveProperty $action): RedirectResponse
    {
        $property = $action->create($request->user(), $request->validated());

        return redirect()
            ->route('admin.accommodation.show', $property)
            ->with('success', 'Draft saved. Add rooms and prices, then publish it.');
    }

    public function show(Property $property): View
    {
        $this->authorize('view', $property);

        return view('admin.accommodation.show', [
            'property' => $property->load([
                'roomTypes.rates',
                'createdBy:id,name',
                'media',
            ]),
            'upcoming' => PropertyBooking::query()
                ->where('property_id', $property->getKey())
                ->holdingInventory()
                ->orderBy('check_in_date')
                ->limit(10)
                ->get(),
            'nextStatuses' => $property->status->allowedTransitions(),
            'currencies' => config('pisfa.currency.supported', ['UGX', 'USD']),
        ]);
    }

    public function edit(Property $property): View
    {
        $this->authorize('update', $property);

        return view('admin.accommodation.edit', ['property' => $property]);
    }

    public function update(
        SavePropertyRequest $request,
        Property $property,
        SaveProperty $action,
    ): RedirectResponse {
        $action->update($request->user(), $property, $request->validated());

        return redirect()
            ->route('admin.accommodation.show', $property)
            ->with('success', 'The property was updated.');
    }

    public function publish(Request $request, Property $property, TransitionProperty $action): RedirectResponse
    {
        $this->authorize('publish', $property);

        $action->publish($request->user(), $property);

        return back()->with('success', 'Live on the site and open for bookings.');
    }

    public function unpublish(Request $request, Property $property, TransitionProperty $action): RedirectResponse
    {
        $this->authorize('publish', $property);

        $action->unpublish($request->user(), $property);

        return back()->with('success', 'Taken off the site. Bookings already made are unaffected.');
    }

    public function archive(Request $request, Property $property, TransitionProperty $action): RedirectResponse
    {
        $this->authorize('publish', $property);

        $action->archive($request->user(), $property);

        return back()->with('success', 'Archived.');
    }

    public function restore(Request $request, Property $property, TransitionProperty $action): RedirectResponse
    {
        $this->authorize('publish', $property);

        $action->restore($request->user(), $property);

        return back()->with('success', 'Back as a draft.');
    }

    public function storeRoomType(Request $request, Property $property, SaveRoomType $action): RedirectResponse
    {
        $this->authorize('update', $property);

        $action->create($request->user(), $property, $request->all());

        return back()->with('success', 'Room added. Give it a price before publishing.');
    }

    public function updateRoomType(
        Request $request,
        Property $property,
        PropertyRoomType $roomType,
        SaveRoomType $action,
    ): RedirectResponse {
        $this->authorize('update', $property);

        abort_unless((int) $roomType->property_id === (int) $property->getKey(), 404);

        $action->update($request->user(), $roomType, $request->all());

        return back()->with('success', 'Room updated.');
    }

    public function storeRoomRate(
        Request $request,
        Property $property,
        PropertyRoomType $roomType,
        SaveRoomRate $action,
    ): RedirectResponse {
        $this->authorize('publish', $property);

        abort_unless((int) $roomType->property_id === (int) $property->getKey(), 404);

        $action->create($request->user(), $roomType, $request->all());

        return back()->with('success', 'Price added.');
    }

    public function deactivateRoomRate(
        Request $request,
        Property $property,
        PropertyRoomRate $roomRate,
        SaveRoomRate $action,
    ): RedirectResponse {
        $this->authorize('publish', $property);

        $roomRate->loadMissing('roomType');

        abort_unless((int) $roomRate->roomType?->property_id === (int) $property->getKey(), 404);

        $action->deactivate($request->user(), $roomRate);

        return back()->with('success', 'Price retired. Bookings priced from it keep the rate they were given.');
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        $counts = [];

        foreach (PropertyStatus::cases() as $case) {
            $counts[$case->value] = Property::query()->where('status', $case->value)->count();
        }

        $counts['pending_stays'] = PropertyBooking::query()
            ->where('status', PropertyBookingStatus::Pending->value)
            ->count();

        return $counts;
    }
}
