<?php

namespace App\Http\Controllers\Admin;

use App\Actions\AirportTransfers\ChangeAirportTransferRateStatus;
use App\Actions\AirportTransfers\SaveAirport;
use App\Actions\AirportTransfers\SaveAirportTransferLocation;
use App\Actions\AirportTransfers\SaveAirportTransferRate;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ChangeAirportStatusRequest;
use App\Http\Requests\Admin\ChangeAirportTransferLocationStatusRequest;
use App\Http\Requests\Admin\ChangeAirportTransferRateStatusRequest;
use App\Http\Requests\Admin\IndexAirportTransferSettingsRequest;
use App\Http\Requests\Admin\SaveAirportRequest;
use App\Http\Requests\Admin\SaveAirportTransferLocationRequest;
use App\Http\Requests\Admin\SaveAirportTransferRateRequest;
use App\Models\Airport;
use App\Models\AirportTransferLocation;
use App\Models\AirportTransferRate;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class AirportTransferSettingController extends Controller
{
    public function index(IndexAirportTransferSettingsRequest $request): View
    {
        $airports = Airport::query()->ordered()->paginate(15, ['*'], 'airports')->withQueryString();
        $locations = AirportTransferLocation::query()
            ->ordered()
            ->paginate(15, ['*'], 'locations')
            ->withQueryString();
        $rates = AirportTransferRate::query()
            ->with(['airport:id,code,name', 'location:id,name,region'])
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->paginate(20, ['*'], 'rates')
            ->withQueryString();

        $airportOptions = Airport::query()->ordered()->get(['id', 'code', 'name', 'is_active']);
        $locationOptions = AirportTransferLocation::query()->ordered()->get(['id', 'name', 'region', 'is_active']);

        return view('admin.airport-transfer-settings.index', compact(
            'airports',
            'locations',
            'rates',
            'airportOptions',
            'locationOptions',
        ));
    }

    public function storeAirport(SaveAirportRequest $request, SaveAirport $action): RedirectResponse
    {
        $action->execute($request->user(), null, $request->validated());

        return back()->with('success', 'The airport was created.');
    }

    public function updateAirport(
        SaveAirportRequest $request,
        Airport $airport,
        SaveAirport $action,
    ): RedirectResponse {
        $action->execute($request->user(), $airport, $request->validated());

        return back()->with('success', 'The airport was updated.');
    }

    public function airportStatus(
        ChangeAirportStatusRequest $request,
        Airport $airport,
        SaveAirport $action,
    ): RedirectResponse {
        $action->execute($request->user(), $airport, [
            'code' => $airport->code,
            'name' => $airport->name,
            'city' => $airport->city,
            'country_code' => $airport->country_code,
            'timezone' => $airport->timezone,
            'terminal_information' => $airport->terminal_information,
            'is_active' => $request->boolean('is_active'),
            'sort_order' => $airport->sort_order,
        ]);

        return back()->with('success', $request->boolean('is_active')
            ? 'The airport now accepts transfer requests.'
            : 'The airport no longer accepts transfer requests.');
    }

    public function storeLocation(
        SaveAirportTransferLocationRequest $request,
        SaveAirportTransferLocation $action,
    ): RedirectResponse {
        $action->execute($request->user(), null, $request->validated());

        return back()->with('success', 'The service location was created.');
    }

    public function updateLocation(
        SaveAirportTransferLocationRequest $request,
        AirportTransferLocation $airportTransferLocation,
        SaveAirportTransferLocation $action,
    ): RedirectResponse {
        $action->execute($request->user(), $airportTransferLocation, $request->validated());

        return back()->with('success', 'The service location was updated.');
    }

    public function locationStatus(
        ChangeAirportTransferLocationStatusRequest $request,
        AirportTransferLocation $airportTransferLocation,
        SaveAirportTransferLocation $action,
    ): RedirectResponse {
        $action->execute($request->user(), $airportTransferLocation, [
            'slug' => $airportTransferLocation->slug,
            'name' => $airportTransferLocation->name,
            'region' => $airportTransferLocation->region,
            'description' => $airportTransferLocation->description,
            'is_active' => $request->boolean('is_active'),
            'sort_order' => $airportTransferLocation->sort_order,
        ]);

        return back()->with('success', $request->boolean('is_active')
            ? 'The service location now accepts transfer requests.'
            : 'The service location no longer accepts transfer requests.');
    }

    public function storeRate(
        SaveAirportTransferRateRequest $request,
        SaveAirportTransferRate $action,
    ): RedirectResponse {
        $validated = $request->validated();
        $airport = Airport::query()->findOrFail($validated['airport_id']);
        $location = AirportTransferLocation::query()->findOrFail($validated['airport_transfer_location_id']);

        $action->execute($request->user(), $airport, $location, $validated);

        return back()->with('success', 'A new transfer rate version was published.');
    }

    public function rateStatus(
        ChangeAirportTransferRateStatusRequest $request,
        AirportTransferRate $airportTransferRate,
        ChangeAirportTransferRateStatus $action,
    ): RedirectResponse {
        $action->execute($request->user(), $airportTransferRate, $request->boolean('is_active'));

        return back()->with('success', $request->boolean('is_active')
            ? 'The transfer rate is active.'
            : 'The transfer rate was deactivated.');
    }
}
