<?php

namespace App\Http\Controllers\Admin;

use App\Actions\AirportTransfers\AssignAirportTransferResources;
use App\Actions\AirportTransfers\RescheduleAirportTransferBooking;
use App\Actions\AirportTransfers\TransitionAirportTransferBooking;
use App\Enums\AccountStatus;
use App\Enums\AirportTransferBookingStatus;
use App\Enums\UserRole;
use App\Enums\VehicleOperationalStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AssignAirportTransferResourcesRequest;
use App\Http\Requests\Admin\IndexAirportTransferBookingsRequest;
use App\Http\Requests\Admin\RescheduleAirportTransferBookingRequest;
use App\Http\Requests\Admin\TransitionAirportTransferBookingRequest;
use App\Models\Airport;
use App\Models\AirportTransferBooking;
use App\Models\AirportTransferLocation;
use App\Models\User;
use App\Models\Vehicle;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class AirportTransferBookingController extends Controller
{
    public function index(IndexAirportTransferBookingsRequest $request): View
    {
        $filters = $request->validated();
        $timezone = (string) config('pisfa.business_timezone', 'Africa/Kampala');

        $query = AirportTransferBooking::query()
            ->with([
                'customer:id,name,email,phone',
                'airport:id,code,name,city',
                'location:id,slug,name,region',
                'assignedDriver:id,name',
                'assignedVehicle:id,slug,make,model,year',
            ])
            ->latest('service_starts_at')
            ->latest('id');

        if (filled($filters['q'] ?? null)) {
            $search = trim((string) $filters['q']);
            $query->where(fn (Builder $nested) => $nested
                ->where('reference', 'like', '%'.$search.'%')
                ->orWhere('contact_name', 'like', '%'.$search.'%')
                ->orWhere('contact_email', 'like', '%'.$search.'%')
                ->orWhere('contact_phone', 'like', '%'.$search.'%')
                ->orWhere('airport_name_snapshot', 'like', '%'.$search.'%')
                ->orWhere('location_name_snapshot', 'like', '%'.$search.'%'));
        }

        foreach (['status', 'transfer_type', 'airport_id', 'airport_transfer_location_id'] as $field) {
            if (filled($filters[$field] ?? null)) {
                $query->where($field, $filters[$field]);
            }
        }

        match ($filters['assignment'] ?? null) {
            'assigned' => $query->whereNotNull('assigned_driver_user_id')->whereNotNull('assigned_vehicle_id'),
            'unassigned' => $query->where(fn (Builder $nested) => $nested
                ->whereNull('assigned_driver_user_id')
                ->orWhereNull('assigned_vehicle_id')),
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

        $bookings = $query->paginate(20)->withQueryString();
        $airports = Airport::query()->ordered()->get(['id', 'code', 'name']);
        $locations = AirportTransferLocation::query()->ordered()->get(['id', 'name', 'region']);

        return view('admin.airport-transfer-bookings.index', compact(
            'bookings',
            'filters',
            'airports',
            'locations',
        ));
    }

    public function show(AirportTransferBooking $airportTransferBooking): View
    {
        $this->authorize('view', $airportTransferBooking);

        $booking = $airportTransferBooking->load([
            'customer:id,name,email,phone',
            'airport', 'location', 'rate',
            'assignedVehicle:id,slug,make,model,year,vehicle_type,seating_capacity,luggage_capacity',
            'assignedDriver:id,name,phone,email',
            'cancelledBy:id,name',
            'assignments.driver:id,name',
            'assignments.vehicle:id,make,model,year',
            'assignments.assignedBy:id,name',
            'assignments.unassignedBy:id,name',
            'events',
        ]);

        $drivers = User::query()
            ->where('role', UserRole::Driver->value)
            ->where('status', AccountStatus::Active->value)
            ->whereNotNull('email_verified_at')
            ->orderBy('name')
            ->get(['id', 'name', 'phone']);

        // Only offer vehicles the assignment action would actually accept: the
        // booked class, available, and large enough for the party and luggage.
        $vehicles = Vehicle::query()
            ->where('operational_status', VehicleOperationalStatus::Available->value)
            ->where('vehicle_type', $booking->vehicle_type_snapshot)
            ->where('seating_capacity', '>=', $booking->passenger_count)
            ->where('luggage_capacity', '>=', $booking->luggage_count)
            ->orderBy('make')
            ->orderBy('model')
            ->get(['id', 'make', 'model', 'year', 'seating_capacity', 'luggage_capacity']);

        return view('admin.airport-transfer-bookings.show', compact('booking', 'drivers', 'vehicles'));
    }

    public function transition(
        TransitionAirportTransferBookingRequest $request,
        AirportTransferBooking $airportTransferBooking,
        TransitionAirportTransferBooking $action,
    ): RedirectResponse {
        $action->execute(
            $request->user(),
            $airportTransferBooking,
            AirportTransferBookingStatus::from($request->validated('status')),
            $request->validated('reason'),
        );

        return back()->with('success', 'The airport transfer status was updated.');
    }

    public function assign(
        AssignAirportTransferResourcesRequest $request,
        AirportTransferBooking $airportTransferBooking,
        AssignAirportTransferResources $action,
    ): RedirectResponse {
        $vehicle = Vehicle::query()->findOrFail($request->validated('vehicle_id'));
        $driver = User::query()->findOrFail($request->validated('driver_user_id'));

        $action->execute(
            $request->user(),
            $airportTransferBooking,
            $vehicle,
            $driver,
            $request->validated('replacement_reason'),
        );

        return back()->with('success', 'The transfer vehicle and driver assignment was saved.');
    }

    public function reschedule(
        RescheduleAirportTransferBookingRequest $request,
        AirportTransferBooking $airportTransferBooking,
        RescheduleAirportTransferBooking $action,
    ): RedirectResponse {
        $attributes = $request->validated();

        // The action treats a present flight time as a required replacement, so
        // an untouched optional field must be absent rather than null.
        if (! filled($attributes['flight_scheduled_at'] ?? null)) {
            unset($attributes['flight_scheduled_at']);
        }

        $action->execute($request->user(), $airportTransferBooking, $attributes);

        return back()->with('success', 'The airport transfer was rescheduled and the team was notified.');
    }
}
