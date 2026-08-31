<?php

namespace App\Actions\Drivers;

use App\Actions\Fleet\RecordOdometerReading;
use App\Enums\DriverTripStatus;
use App\Enums\InspectionPhase;
use App\Models\DriverTrip;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\AuditLogger;
use App\Support\Drivers\AssignmentSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A driver takes a vehicle out.
 *
 * A trip cannot start until a pre-trip check has been recorded and passed. That
 * ordering is the whole point of the check: one that can be skipped, or done
 * afterwards, authorises nothing.
 */
class StartDriverTrip
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /** @param array<string, mixed> $attributes */
    public function execute(User $driver, Model $assignment, array $attributes): DriverTrip
    {
        $source = AssignmentSource::forModel($assignment);

        if ($source === null) {
            throw ValidationException::withMessages([
                'assignment' => 'That is not a driver assignment.',
            ]);
        }

        $validated = Validator::make($attributes, [
            'odometer_km' => ['nullable', 'integer', 'min:0', 'max:5000000'],
            'driver_notes' => ['nullable', 'string', 'max:2000'],
        ])->validate();

        return DB::transaction(function () use ($driver, $assignment, $source, $validated): DriverTrip {
            $lockedDriver = DriverAccess::lockedDriver($driver);

            // Re-read under a lock so an assignment withdrawn a moment ago
            // cannot still be started.
            $lockedAssignment = $assignment->newQuery()
                ->whereKey($assignment->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            DriverAccess::assertOwns($lockedDriver, $lockedAssignment);

            $existing = DriverTrip::query()
                ->where('assignment_type', $lockedAssignment->getMorphClass())
                ->where('assignment_id', $lockedAssignment->getKey())
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $this->startExisting($lockedDriver, $existing, $validated);
            }

            $vehicleId = $this->vehicleIdFor($source, $lockedAssignment);

            $trip = new DriverTrip;

            try {
                $trip->forceFill([
                    'reference' => 'TRP-'.Str::upper((string) Str::ulid()),
                    'assignment_type' => $lockedAssignment->getMorphClass(),
                    'assignment_id' => $lockedAssignment->getKey(),
                    'driver_user_id' => $lockedDriver->getKey(),
                    'vehicle_id' => $vehicleId,
                    'status' => DriverTripStatus::Scheduled,
                ])->save();
            } catch (QueryException) {
                // The unique (assignment_type, assignment_id) index caught a
                // concurrent start. Whoever won has written the trip.
                return DriverTrip::query()
                    ->where('assignment_type', $lockedAssignment->getMorphClass())
                    ->where('assignment_id', $lockedAssignment->getKey())
                    ->firstOrFail();
            }

            return $this->startExisting($lockedDriver, $trip, $validated);
        }, 3);
    }

    /**
     * Creates the trip row without starting it.
     *
     * A pre-trip check has to exist before the wheels turn, and it hangs off the
     * trip, so the row is needed before the driver can record one.
     */
    public function prepare(User $driver, Model $assignment): DriverTrip
    {
        $source = AssignmentSource::forModel($assignment);

        if ($source === null) {
            throw ValidationException::withMessages([
                'assignment' => 'That is not a driver assignment.',
            ]);
        }

        return DB::transaction(function () use ($driver, $assignment, $source): DriverTrip {
            $lockedDriver = DriverAccess::lockedDriver($driver);

            $lockedAssignment = $assignment->newQuery()
                ->whereKey($assignment->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            DriverAccess::assertOwns($lockedDriver, $lockedAssignment);

            $existing = DriverTrip::query()
                ->where('assignment_type', $lockedAssignment->getMorphClass())
                ->where('assignment_id', $lockedAssignment->getKey())
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $trip = new DriverTrip;

            try {
                $trip->forceFill([
                    'reference' => 'TRP-'.Str::upper((string) Str::ulid()),
                    'assignment_type' => $lockedAssignment->getMorphClass(),
                    'assignment_id' => $lockedAssignment->getKey(),
                    'driver_user_id' => $lockedDriver->getKey(),
                    'vehicle_id' => $this->vehicleIdFor($source, $lockedAssignment),
                    'status' => DriverTripStatus::Scheduled,
                ])->save();
            } catch (QueryException) {
                return DriverTrip::query()
                    ->where('assignment_type', $lockedAssignment->getMorphClass())
                    ->where('assignment_id', $lockedAssignment->getKey())
                    ->firstOrFail();
            }

            return $trip;
        }, 3);
    }

    /** @param array<string, mixed> $validated */
    private function startExisting(User $driver, DriverTrip $trip, array $validated): DriverTrip
    {
        if ($trip->status === DriverTripStatus::InProgress) {
            return $trip;
        }

        if (! $trip->canTransitionTo(DriverTripStatus::InProgress)) {
            throw ValidationException::withMessages([
                'status' => 'A '.mb_strtolower($trip->status->label()).' trip cannot be started.',
            ]);
        }

        $trip->loadMissing('inspections');
        $preTrip = $trip->inspectionFor(InspectionPhase::PreTrip);

        if ($trip->tracksOdometer()) {
            // A check that can be skipped authorises nothing.
            if ($preTrip === null) {
                throw ValidationException::withMessages([
                    'inspection' => 'Record the pre-trip vehicle check before taking the vehicle out.',
                ]);
            }

            if (! $preTrip->passed) {
                throw ValidationException::withMessages([
                    'inspection' => 'The pre-trip check failed. The vehicle must not leave until the defect is cleared.',
                ]);
            }
        }

        $odometer = $validated['odometer_km'] ?? $preTrip?->odometer_km;

        if ($trip->tracksOdometer() && $odometer !== null) {
            $vehicle = Vehicle::query()
                ->whereKey($trip->vehicle_id)
                ->lockForUpdate()
                ->firstOrFail();

            // The same guard the fleet console uses: one place decides whether
            // a reading is plausible.
            RecordOdometerReading::advance($vehicle, (int) $odometer);
        }

        $trip->forceFill([
            'status' => DriverTripStatus::InProgress,
            'started_at' => now(),
            'start_odometer_km' => $odometer,
            'driver_notes' => $validated['driver_notes'] ?? $trip->driver_notes,
        ])->save();

        $this->auditLogger->record(
            event: 'driver_trip.started',
            auditable: $trip,
            newValues: [
                'reference' => $trip->reference,
                'vehicle_id' => $trip->vehicle_id,
                'start_odometer_km' => $trip->start_odometer_km,
            ],
            user: $driver,
        );

        return $trip->fresh(['vehicle', 'inspections']);
    }

    private function vehicleIdFor(AssignmentSource $source, Model $assignment): ?int
    {
        $column = $source->vehicleColumn();

        if ($column === null) {
            return null;
        }

        $value = $source->vehicleOnAssignment()
            ? $assignment->getAttribute($column)
            : DB::table($source->bookingTable())
                ->where('id', $assignment->getAttribute($source->bookingForeignKey()))
                ->value($column);

        return $value === null ? null : (int) $value;
    }
}
