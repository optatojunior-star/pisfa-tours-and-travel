<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Fleet\CompleteMaintenanceRecord;
use App\Actions\Fleet\SaveMaintenanceRecord;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CompleteMaintenanceRequest;
use App\Http\Requests\Admin\SaveMaintenanceRequest;
use App\Models\Vehicle;
use App\Models\VehicleMaintenanceRecord;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class VehicleMaintenanceController extends Controller
{
    public function store(
        SaveMaintenanceRequest $request,
        Vehicle $vehicle,
        SaveMaintenanceRecord $action,
    ): RedirectResponse {
        $record = $action->create($request->user(), $vehicle, $request->validated());

        return redirect()
            ->route('admin.fleet.show', $vehicle)
            ->with('success', 'Maintenance '.$record->reference.' was scheduled.');
    }

    public function update(
        SaveMaintenanceRequest $request,
        VehicleMaintenanceRecord $maintenance,
        SaveMaintenanceRecord $action,
    ): RedirectResponse {
        $action->update($request->user(), $maintenance, $request->validated());

        return back()->with('success', 'The maintenance record was updated.');
    }

    public function start(
        Request $request,
        VehicleMaintenanceRecord $maintenance,
        CompleteMaintenanceRecord $action,
    ): RedirectResponse {
        $this->authorize('manageFleet', $maintenance->vehicle);

        $action->start($request->user(), $maintenance);

        return back()->with('success', 'Work started. The vehicle is off hire until it is closed.');
    }

    public function complete(
        CompleteMaintenanceRequest $request,
        VehicleMaintenanceRecord $maintenance,
        CompleteMaintenanceRecord $action,
    ): RedirectResponse {
        $action->complete($request->user(), $maintenance, $request->validated());

        return back()->with('success', 'The work was closed and the odometer updated.');
    }

    public function cancel(
        Request $request,
        VehicleMaintenanceRecord $maintenance,
        CompleteMaintenanceRecord $action,
    ): RedirectResponse {
        $this->authorize('manageFleet', $maintenance->vehicle);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ]);

        $action->cancel($request->user(), $maintenance, (string) $validated['reason']);

        return back()->with('success', 'The maintenance record was cancelled.');
    }
}
