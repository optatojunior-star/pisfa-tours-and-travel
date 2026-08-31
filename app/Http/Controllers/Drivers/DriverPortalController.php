<?php

namespace App\Http\Controllers\Drivers;

use App\Actions\Drivers\CompleteDriverTrip;
use App\Actions\Drivers\RecordVehicleInspection;
use App\Actions\Drivers\StartDriverTrip;
use App\Enums\DriverTripStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Drivers\CloseDriverTripRequest;
use App\Http\Requests\Drivers\RecordInspectionRequest;
use App\Http\Requests\Drivers\StartDriverTripRequest;
use App\Models\DriverTrip;
use App\Models\User;
use App\Services\Drivers\DriverAssignmentQuery;
use App\Support\Drivers\AssignmentSource;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DriverPortalController extends Controller
{
    public function __construct(private readonly DriverAssignmentQuery $assignments) {}

    /** The driver's own schedule: today first, then what is coming. */
    public function index(Request $request): View
    {
        $driver = $this->driver($request);
        $timezone = (string) config('pisfa.business_timezone', 'Africa/Kampala');
        $now = CarbonImmutable::now($timezone);

        return view('drivers.index', [
            'driver' => $driver,
            'today' => $this->assignments->forDay($driver, $now),
            'upcoming' => $this->assignments->upcoming($driver, $now->utc()),
            'openTrips' => DriverTrip::query()
                ->forDriver($driver)
                ->where('status', DriverTripStatus::InProgress->value)
                ->with('vehicle')
                ->get(),
            'profile' => $driver->driverProfile,
            'timezone' => $timezone,
            'now' => $now,
        ]);
    }

    public function history(Request $request): View
    {
        $driver = $this->driver($request);

        return view('drivers.history', [
            'driver' => $driver,
            'trips' => DriverTrip::query()
                ->forDriver($driver)
                ->whereIn('status', [DriverTripStatus::Completed->value, DriverTripStatus::Abandoned->value])
                ->with(['vehicle', 'inspections'])
                ->latest('completed_at')
                ->paginate(20),
        ]);
    }

    /** One job: the assignment, its trip, and the checks against it. */
    public function show(Request $request, string $source, int $assignment): View
    {
        $driver = $this->driver($request);
        $assignmentModel = $this->resolveAssignment($driver, $source, $assignment);

        $trip = DriverTrip::query()
            ->where('assignment_type', $assignmentModel->getMorphClass())
            ->where('assignment_id', $assignmentModel->getKey())
            ->with(['vehicle', 'inspections'])
            ->first();

        return view('drivers.show', [
            'driver' => $driver,
            'source' => AssignmentSource::from($source),
            'assignment' => $assignmentModel,
            'booking' => $assignmentModel->booking,
            'trip' => $trip,
            'timezone' => config('pisfa.business_timezone', 'Africa/Kampala'),
        ]);
    }

    public function startTrip(
        StartDriverTripRequest $request,
        string $source,
        int $assignment,
        StartDriverTrip $action,
    ): RedirectResponse {
        $driver = $this->driver($request);
        $assignmentModel = $this->resolveAssignment($driver, $source, $assignment);

        $action->execute($driver, $assignmentModel, $request->validated());

        return redirect()
            ->route('drivers.jobs.show', [$source, $assignment])
            ->with('success', 'Trip started. Drive safely.');
    }

    public function recordInspection(
        RecordInspectionRequest $request,
        string $source,
        int $assignment,
        StartDriverTrip $start,
        RecordVehicleInspection $action,
    ): RedirectResponse {
        $driver = $this->driver($request);
        $assignmentModel = $this->resolveAssignment($driver, $source, $assignment);

        // A check can be recorded before the trip has been started, which is
        // exactly the point of a pre-trip check, so the trip row is created
        // first if it does not exist yet.
        $trip = DriverTrip::query()
            ->where('assignment_type', $assignmentModel->getMorphClass())
            ->where('assignment_id', $assignmentModel->getKey())
            ->first() ?? $start->prepare($driver, $assignmentModel);

        $inspection = $action->execute($driver, $trip, $request->validated());

        return redirect()
            ->route('drivers.jobs.show', [$source, $assignment])
            ->with('success', $inspection->passed
                ? 'Check recorded. The vehicle passed.'
                : 'Check recorded. The vehicle failed on a critical item and has been taken off hire.');
    }

    public function completeTrip(
        CloseDriverTripRequest $request,
        DriverTrip $driverTrip,
        CompleteDriverTrip $action,
    ): RedirectResponse {
        $action->execute($request->user(), $driverTrip, $request->validated());

        return redirect()
            ->route('drivers.index')
            ->with('success', 'Trip closed. Thank you.');
    }

    public function abandonTrip(
        Request $request,
        DriverTrip $driverTrip,
        CompleteDriverTrip $action,
    ): RedirectResponse {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ]);

        $action->abandon($request->user(), $driverTrip, (string) $validated['reason']);

        return redirect()
            ->route('drivers.index')
            ->with('success', 'The job was recorded as abandoned.');
    }

    private function driver(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    /**
     * Resolves an assignment the driver actually owns.
     *
     * A foreign or withdrawn assignment is a 404, not a 403: a driver learns
     * nothing about whose job it is.
     */
    private function resolveAssignment(User $driver, string $source, int $assignment)
    {
        $assignmentSource = AssignmentSource::tryFrom($source);
        abort_if($assignmentSource === null, 404);

        $model = $assignmentSource->model();

        return $model::query()
            ->whereKey($assignment)
            ->where('driver_user_id', $driver->getKey())
            ->whereNull('unassigned_at')
            ->firstOrFail();
    }
}
