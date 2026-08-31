<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Fleet\RecordFuelLog;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RecordFuelLogRequest;
use App\Models\Vehicle;
use Illuminate\Http\RedirectResponse;

class VehicleFuelController extends Controller
{
    public function store(
        RecordFuelLogRequest $request,
        Vehicle $vehicle,
        RecordFuelLog $action,
    ): RedirectResponse {
        $log = $action->execute($request->user(), $vehicle, $request->validated());

        return redirect()
            ->route('admin.fleet.show', $vehicle)
            ->with('success', 'Recorded '.$log->formattedVolume().' at '
                .number_format($log->odometer_km).' km.');
    }
}
