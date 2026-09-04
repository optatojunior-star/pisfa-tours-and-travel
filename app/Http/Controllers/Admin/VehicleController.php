<?php

namespace App\Http\Controllers\Admin;

use App\Actions\CarHire\SaveVehicle;
use App\Actions\CarHire\SaveVehicleRate;
use App\Enums\DocumentCategory;
use App\Enums\VehicleCatalogueStatus;
use App\Enums\VehicleOperationalStatus;
use App\Http\Controllers\Concerns\HandlesImageUploads;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ChangeVehicleStatusRequest;
use App\Http\Requests\Admin\IndexVehiclesRequest;
use App\Http\Requests\Admin\SaveVehicleRateRequest;
use App\Http\Requests\Admin\SaveVehicleRequest;
use App\Models\Vehicle;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class VehicleController extends Controller
{
    use HandlesImageUploads;

    public function index(IndexVehiclesRequest $request): View
    {
        $filters = $request->validated();
        $query = Vehicle::query()
            ->with('coverMedia')
            ->withCount(['hireRates', 'bookings']);

        if (filled($filters['q'] ?? null)) {
            $query->search((string) $filters['q']);
        }
        foreach (['catalogue_status', 'operational_status', 'vehicle_type'] as $field) {
            if (filled($filters[$field] ?? null)) {
                $query->where($field, $filters[$field]);
            }
        }

        $vehicles = $query->latest('updated_at')->latest('id')->paginate(20)->withQueryString();
        $vehicleTypes = Vehicle::query()->distinct()->orderBy('vehicle_type')->pluck('vehicle_type');

        return view('admin.vehicles.index', compact('vehicles', 'vehicleTypes', 'filters'));
    }

    /**
     * Which of the three kinds of vehicle is this?
     *
     * Hire, sale and import are genuinely different records — different prices,
     * different customers, different paperwork — and they lived in three parts
     * of the menu with nothing connecting them. Somebody adding a car they have
     * just bought to resell had no reason to guess it belonged under
     * "Showroom" rather than "Vehicles".
     */
    public function choose(): View
    {
        $this->authorize('viewAny', Vehicle::class);

        return view('admin.vehicles.choose');
    }

    public function create(): View
    {
        $this->authorize('create', Vehicle::class);
        $vehicle = new Vehicle([
            'year' => now()->year,
            'condition' => 'good',
            'vehicle_type' => 'suv',
            'fuel_type' => 'petrol',
            'transmission' => 'automatic',
            'seating_capacity' => 5,
            'luggage_capacity' => 2,
            'catalogue_status' => VehicleCatalogueStatus::Draft,
            'operational_status' => VehicleOperationalStatus::Available,
        ]);

        return view('admin.vehicles.create', compact('vehicle'));
    }

    public function store(SaveVehicleRequest $request, SaveVehicle $action): RedirectResponse
    {
        $attributes = $request->validated();
        $attributes['catalogue_status'] = VehicleCatalogueStatus::Draft->value;
        $vehicle = $action->execute($request->user(), $attributes);
        $rejected = $this->copyUploadsToMedia($request, $vehicle, DocumentCategory::VehicleMedia, $vehicle->make.' '.$vehicle->model);

        return $this->withRejectedImages(
            redirect()->route('admin.vehicles.show', $vehicle)
                ->with('success', 'Vehicle saved as a draft. Add an effective rate before publishing it.'),
            $rejected,
        );
    }

    public function show(Vehicle $vehicle): View
    {
        $this->authorize('view', $vehicle);
        $vehicle->load(['media', 'hireRates.createdBy'])->loadCount('bookings');

        return view('admin.vehicles.show', compact('vehicle'));
    }

    public function edit(Vehicle $vehicle): View
    {
        $this->authorize('update', $vehicle);
        $vehicle->load('media');

        return view('admin.vehicles.edit', compact('vehicle'));
    }

    public function update(SaveVehicleRequest $request, Vehicle $vehicle, SaveVehicle $action): RedirectResponse
    {
        $vehicle = $action->execute($request->user(), $request->validated(), $vehicle);
        $rejected = $this->copyUploadsToMedia($request, $vehicle, DocumentCategory::VehicleMedia, $vehicle->make.' '.$vehicle->model);

        return $this->withRejectedImages(
            redirect()->route('admin.vehicles.show', $vehicle)->with('success', 'Vehicle details were updated.'),
            $rejected,
        );
    }

    public function status(ChangeVehicleStatusRequest $request, Vehicle $vehicle, SaveVehicle $action): RedirectResponse
    {
        $attributes = $this->requiredAttributes($vehicle);
        foreach (['catalogue_status', 'operational_status'] as $field) {
            if ($request->has($field)) {
                $attributes[$field] = $request->validated($field);
            }
        }
        $action->execute($request->user(), $attributes, $vehicle);

        return back()->with('success', 'Vehicle status was updated.');
    }

    public function storeRate(SaveVehicleRateRequest $request, Vehicle $vehicle, SaveVehicleRate $action): RedirectResponse
    {
        $action->execute($request->user(), $vehicle, $request->validated());

        return back()->with('success', 'A new immutable rate version was added.');
    }

    /** @return array<string, mixed> */
    private function requiredAttributes(Vehicle $vehicle): array
    {
        return [
            'slug' => $vehicle->slug,
            'registration_plate' => $vehicle->registration_plate,
            'make' => $vehicle->make,
            'model' => $vehicle->model,
            'year' => $vehicle->year,
            'color' => $vehicle->color,
            'condition' => $vehicle->condition,
            'vehicle_type' => $vehicle->vehicle_type,
            'fuel_type' => $vehicle->fuel_type,
            'transmission' => $vehicle->transmission,
            'seating_capacity' => $vehicle->seating_capacity,
            'luggage_capacity' => $vehicle->luggage_capacity,
            'summary' => $vehicle->summary,
            'description' => $vehicle->description,
            'catalogue_status' => $vehicle->catalogue_status->value,
            'operational_status' => $vehicle->operational_status->value,
            'is_featured' => $vehicle->is_featured,
        ];
    }
}
