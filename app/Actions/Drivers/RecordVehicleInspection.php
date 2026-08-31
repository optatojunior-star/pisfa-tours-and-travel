<?php

namespace App\Actions\Drivers;

use App\Enums\InspectionPhase;
use App\Enums\MaintenanceStatus;
use App\Enums\MaintenanceType;
use App\Enums\VehicleOperationalStatus;
use App\Models\DriverTrip;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleInspection;
use App\Models\VehicleMaintenanceRecord;
use App\Services\AuditLogger;
use App\Support\Fleet\InspectionChecklist;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Records a driver's vehicle check.
 *
 * A reported defect does not merely sit in a text field: it raises a repair job
 * in the fleet queue, and a critical failure takes the vehicle off hire. A check
 * that produced nothing anyone acted on would be paperwork, not safety.
 */
class RecordVehicleInspection
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /** @param array<string, mixed> $attributes */
    public function execute(User $driver, DriverTrip $trip, array $attributes): VehicleInspection
    {
        $validated = Validator::make($attributes, [
            'phase' => ['required', Rule::enum(InspectionPhase::class)],
            'odometer_km' => ['required', 'integer', 'min:0', 'max:5000000'],
            'answers' => ['required', 'array'],
            'defect_notes' => ['nullable', 'string', 'max:2000'],
        ])->validate();

        $phase = InspectionPhase::from((string) $validated['phase']);
        // Normalised to the known checklist, so a stored inspection always has
        // the same shape regardless of what a form posted.
        $answers = InspectionChecklist::normalise((array) $validated['answers']);
        $defects = InspectionChecklist::defects($answers);
        $criticalFailures = InspectionChecklist::criticalFailures($answers);

        if ($defects !== [] && blank($validated['defect_notes'] ?? null)) {
            throw ValidationException::withMessages([
                'defect_notes' => 'Describe the defect so the workshop knows what to look at.',
            ]);
        }

        return DB::transaction(function () use (
            $driver,
            $trip,
            $phase,
            $validated,
            $answers,
            $defects,
            $criticalFailures,
        ): VehicleInspection {
            $lockedDriver = DriverAccess::lockedDriver($driver);

            $locked = DriverTrip::query()
                ->whereKey($trip->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $locked->driver_user_id !== (int) $lockedDriver->getKey()) {
                throw new AuthorizationException;
            }

            if (! $locked->tracksOdometer()) {
                throw ValidationException::withMessages([
                    'vehicle' => 'This job has no fleet vehicle to check.',
                ]);
            }

            // Vehicle before inspection, matching the lock order used across
            // the fleet actions, so concurrent work cannot deadlock.
            $vehicle = Vehicle::query()
                ->whereKey($locked->vehicle_id)
                ->lockForUpdate()
                ->firstOrFail();

            $passed = $criticalFailures === [];
            $maintenance = null;

            if ($defects !== []) {
                $maintenance = $this->raiseRepair(
                    $lockedDriver,
                    $vehicle,
                    $locked,
                    $defects,
                    (string) ($validated['defect_notes'] ?? ''),
                );
            }

            $inspection = new VehicleInspection;

            try {
                $inspection->forceFill([
                    'vehicle_id' => $vehicle->getKey(),
                    'driver_user_id' => $lockedDriver->getKey(),
                    'driver_trip_id' => $locked->getKey(),
                    'phase' => $phase,
                    'odometer_km' => (int) $validated['odometer_km'],
                    'answers' => $answers,
                    'has_defects' => $defects !== [],
                    'passed' => $passed,
                    'defect_notes' => $validated['defect_notes'] ?? null,
                    'maintenance_record_id' => $maintenance?->getKey(),
                ])->save();
            } catch (QueryException) {
                // One check of each phase per trip: a driver cannot quietly
                // redo a failed pre-trip check until it passes.
                throw ValidationException::withMessages([
                    'phase' => 'A '.mb_strtolower($phase->label()).' has already been recorded for this trip.',
                ]);
            }

            // A vehicle that failed a critical item does not go out, and does
            // not go back on hire if it came back that way.
            if (! $passed && $vehicle->operational_status === VehicleOperationalStatus::Available) {
                $vehicle->forceFill([
                    'operational_status' => VehicleOperationalStatus::Maintenance,
                ])->save();
            }

            $this->auditLogger->record(
                event: 'vehicle_inspection.recorded',
                auditable: $inspection,
                newValues: [
                    'vehicle_id' => $vehicle->getKey(),
                    'phase' => $phase->value,
                    'passed' => $passed,
                    'defects' => $defects,
                    'maintenance_reference' => $maintenance?->reference,
                ],
                user: $lockedDriver,
            );

            return $inspection->fresh(['vehicle', 'maintenanceRecord']);
        }, 3);
    }

    /**
     * Turns reported defects into a scheduled repair, so the fleet console sees
     * them rather than only the inspection record.
     *
     * @param  list<string>  $defects
     */
    private function raiseRepair(
        User $driver,
        Vehicle $vehicle,
        DriverTrip $trip,
        array $defects,
        string $notes,
    ): VehicleMaintenanceRecord {
        $labels = array_map(
            static fn (string $key): string => InspectionChecklist::label($key),
            $defects,
        );

        $record = new VehicleMaintenanceRecord;
        $record->forceFill([
            'reference' => 'MNT-'.Str::upper((string) Str::ulid()),
            'vehicle_id' => $vehicle->getKey(),
            'type' => MaintenanceType::Repair,
            'status' => MaintenanceStatus::Scheduled,
            'title' => 'Defect reported: '.Str::limit(implode(', ', $labels), 150),
            'description' => $notes,
            'currency' => (string) config('pisfa.currency.default', 'UGX'),
            'recorded_by_user_id' => $driver->getKey(),
            'internal_notes' => 'Raised automatically from '.$trip->reference.'.',
        ])->save();

        return $record;
    }
}
