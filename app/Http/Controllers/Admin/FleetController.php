<?php

namespace App\Http\Controllers\Admin;

use App\Enums\MaintenanceStatus;
use App\Enums\VehicleOperationalStatus;
use App\Http\Controllers\Controller;
use App\Models\Vehicle;
use App\Models\VehicleMaintenanceRecord;
use App\Services\Fleet\FleetReport;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FleetController extends Controller
{
    public function __construct(private readonly FleetReport $report) {}

    /** Fleet-wide overview: what is off the road, due, or expiring. */
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Vehicle::class);

        $now = CarbonImmutable::now();
        $windowDays = (int) config('fleet.reporting.utilisation_window_days', 30);
        $noticeDays = (int) config('fleet.alerts.document_notice_days', 30);

        $vehicles = Vehicle::query()
            ->withCount(['openMaintenance'])
            ->orderBy('make')
            ->orderBy('model')
            ->get();

        return view('admin.fleet.index', [
            'vehicles' => $vehicles,
            'utilisation' => $this->report->utilisation($now->subDays($windowDays), $now),
            'utilisationDays' => $windowDays,
            'expiring' => $this->report->expiringDocuments($noticeDays, $now),
            'noticeDays' => $noticeDays,
            'dueRecords' => VehicleMaintenanceRecord::query()
                ->due($now)
                ->with('vehicle')
                ->orderBy('next_due_on')
                ->limit(25)
                ->get(),
            'counts' => [
                'total' => $vehicles->count(),
                'off_road' => $vehicles->filter(
                    fn (Vehicle $vehicle): bool => $vehicle->operational_status !== VehicleOperationalStatus::Available,
                )->count(),
                'open_jobs' => VehicleMaintenanceRecord::query()->open()->count(),
            ],
            'now' => $now,
        ]);
    }

    /** One vehicle's fleet record: odometer, jobs, fuel, costs, compliance. */
    public function show(Request $request, Vehicle $vehicle): View
    {
        $this->authorize('view', $vehicle);

        $months = (int) config('fleet.reporting.cost_window_months', 12);
        $since = CarbonImmutable::now()->subMonths($months);

        return view('admin.fleet.show', [
            'vehicle' => $vehicle->load([
                'maintenanceRecords.recordedBy:id,name',
                'fuelLogs.recordedBy:id,name',
                'documents',
            ]),
            'costs' => $this->report->costsFor($vehicle, $since),
            'consumption' => $this->report->consumptionFor($vehicle),
            'costWindowMonths' => $months,
            'openJobs' => $vehicle->maintenanceRecords
                ->filter(fn (VehicleMaintenanceRecord $record): bool => $record->status->isOpen()),
            'maintenanceStatuses' => MaintenanceStatus::cases(),
        ]);
    }
}
