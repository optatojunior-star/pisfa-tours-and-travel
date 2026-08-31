<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Sales\SaveVehicleListing;
use App\Actions\Sales\TransitionVehicleListing;
use App\Enums\ListingStatus;
use App\Enums\SalesEnquiryStatus;
use App\Enums\VehicleCatalogueStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveVehicleListingRequest;
use App\Models\Vehicle;
use App\Models\VehicleListing;
use App\Models\VehicleSalesEnquiry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class VehicleListingController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', VehicleListing::class);

        $statusInput = $request->query('status');
        $status = is_string($statusInput) ? ListingStatus::tryFrom($statusInput) : null;

        $query = VehicleListing::query()
            ->with(['vehicle:id,registration_plate', 'media'])
            ->withCount(['enquiries as open_enquiries_count' => fn ($enquiries) => $enquiries->open()])
            ->latest('id');

        if ($status !== null) {
            $query->where('status', $status->value);
        }

        if (filled($request->query('q'))) {
            $query->search((string) $request->query('q'));
        }

        return view('admin.showroom.index', [
            'listings' => $query->paginate(20)->withQueryString(),
            'status' => $status,
            'search' => $request->query('q'),
            'counts' => $this->counts(),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', VehicleListing::class);

        $vehicleId = $request->query('vehicle');
        $vehicle = is_numeric($vehicleId)
            ? Vehicle::query()->whereKey((int) $vehicleId)->first()
            : null;

        return view('admin.showroom.create', [
            'listing' => null,
            'vehicle' => $vehicle,
            'prefill' => $vehicle === null ? [] : SaveVehicleListing::prefillFrom($vehicle),
            'vehicles' => $this->sellableVehicles(),
        ]);
    }

    public function store(SaveVehicleListingRequest $request, SaveVehicleListing $action): RedirectResponse
    {
        $vehicleId = $request->validated('vehicle_id');

        $vehicle = $vehicleId === null
            ? null
            : Vehicle::query()->whereKey((int) $vehicleId)->firstOrFail();

        $listing = $action->create($request->user(), $request->validated(), $vehicle);

        return redirect()
            ->route('admin.showroom.show', $listing)
            ->with('success', 'Draft listing saved. Publish it to the showroom when the photographs are up.');
    }

    public function show(VehicleListing $listing): View
    {
        $this->authorize('view', $listing);

        return view('admin.showroom.show', [
            'listing' => $listing->load(['vehicle', 'createdBy:id,name', 'soldToEnquiry', 'media']),
            'enquiries' => $listing->enquiries()->with('assignee:id,name')->get(),
            'nextStatuses' => $listing->status->allowedTransitions(),
        ]);
    }

    public function edit(VehicleListing $listing): View
    {
        $this->authorize('update', $listing);

        return view('admin.showroom.edit', [
            'listing' => $listing->load('vehicle'),
            'vehicle' => $listing->vehicle,
            'prefill' => [],
            'vehicles' => $this->sellableVehicles(),
        ]);
    }

    public function update(
        SaveVehicleListingRequest $request,
        VehicleListing $listing,
        SaveVehicleListing $action,
    ): RedirectResponse {
        $action->update($request->user(), $listing, $request->validated());

        return redirect()
            ->route('admin.showroom.show', $listing)
            ->with('success', 'The listing was updated.');
    }

    public function publish(Request $request, VehicleListing $listing, TransitionVehicleListing $action): RedirectResponse
    {
        $this->authorize('transition', $listing);

        $action->list($request->user(), $listing);

        return back()->with('success', 'Live in the showroom.');
    }

    public function reserve(Request $request, VehicleListing $listing, TransitionVehicleListing $action): RedirectResponse
    {
        $this->authorize('transition', $listing);

        $validated = $request->validate([
            'enquiry_id' => ['nullable', 'integer', 'exists:vehicle_sales_enquiries,id'],
        ]);

        $enquiry = isset($validated['enquiry_id'])
            ? VehicleSalesEnquiry::query()->whereKey((int) $validated['enquiry_id'])->first()
            : null;

        $action->reserve($request->user(), $listing, $enquiry);

        return back()->with('success', 'Reserved. It is off the market but still shown as reserved.');
    }

    public function release(Request $request, VehicleListing $listing, TransitionVehicleListing $action): RedirectResponse
    {
        $this->authorize('transition', $listing);

        $action->release($request->user(), $listing);

        return back()->with('success', 'Back on the market.');
    }

    public function restore(Request $request, VehicleListing $listing, TransitionVehicleListing $action): RedirectResponse
    {
        $this->authorize('transition', $listing);

        $action->restore($request->user(), $listing);

        return back()->with('success', 'Back as a draft. Check the price and photographs before publishing it again.');
    }

    public function withdraw(Request $request, VehicleListing $listing, TransitionVehicleListing $action): RedirectResponse
    {
        $this->authorize('transition', $listing);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ]);

        $action->withdraw($request->user(), $listing, (string) $validated['reason']);

        return back()->with('success', 'Withdrawn from the showroom.');
    }

    public function sell(Request $request, VehicleListing $listing, TransitionVehicleListing $action): RedirectResponse
    {
        $this->authorize('sell', $listing);

        $validated = $request->validate([
            'sold_price' => ['required', 'string', 'max:24'],
            'buyer_enquiry_id' => ['nullable', 'integer', 'exists:vehicle_sales_enquiries,id'],
        ]);

        $buyer = isset($validated['buyer_enquiry_id'])
            ? VehicleSalesEnquiry::query()->whereKey((int) $validated['buyer_enquiry_id'])->first()
            : null;

        $sold = $action->sell($request->user(), $listing, (string) $validated['sold_price'], $buyer);

        return back()->with('success', 'Sale recorded at '.$sold->formattedSoldPrice()
            .($sold->vehicle_id === null ? '.' : '. The vehicle has been retired from the hire fleet.'));
    }

    /**
     * Fleet vehicles that could be listed.
     *
     * The definitive check runs inside the action under a lock; this only keeps
     * obviously impossible choices out of the dropdown.
     *
     * @return Collection<int, Vehicle>
     */
    private function sellableVehicles(): Collection
    {
        return Vehicle::query()
            ->where('catalogue_status', '!=', VehicleCatalogueStatus::Archived->value)
            ->whereNotIn('id', VehicleListing::query()
                ->inStock()
                ->whereNotNull('vehicle_id')
                ->select('vehicle_id'))
            ->orderBy('make')
            ->orderBy('model')
            ->get(['id', 'registration_plate', 'make', 'model', 'year']);
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        $counts = [];

        foreach (ListingStatus::cases() as $case) {
            $counts[$case->value] = VehicleListing::query()->where('status', $case->value)->count();
        }

        $counts['open_enquiries'] = VehicleSalesEnquiry::query()
            ->whereIn('status', SalesEnquiryStatus::openValues())
            ->count();

        return $counts;
    }
}
