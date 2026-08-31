<?php

namespace App\Actions\Drivers;

use App\Actions\Fleet\RecordOdometerReading;
use App\Enums\DriverTripStatus;
use App\Enums\InspectionPhase;
use App\Models\DriverTrip;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * A driver hands the vehicle back.
 *
 * The closing odometer goes through the same guard the fleet console uses, so a
 * trip's mileage lands in exactly one place and cannot disagree with a fuel log
 * or a service record.
 */
class CompleteDriverTrip
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /** @param array<string, mixed> $attributes */
    public function execute(User $driver, DriverTrip $trip, array $attributes): DriverTrip
    {
        $validated = Validator::make($attributes, [
            'odometer_km' => ['nullable', 'integer', 'min:0', 'max:5000000'],
            'driver_notes' => ['nullable', 'string', 'max:2000'],
        ])->validate();

        return DB::transaction(function () use ($driver, $trip, $validated): DriverTrip {
            $lockedDriver = DriverAccess::lockedDriver($driver);

            $locked = DriverTrip::query()
                ->with('inspections')
                ->whereKey($trip->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $locked->driver_user_id !== (int) $lockedDriver->getKey()) {
                throw new AuthorizationException;
            }

            if ($locked->status === DriverTripStatus::Completed) {
                return $locked;
            }

            if (! $locked->canTransitionTo(DriverTripStatus::Completed)) {
                throw ValidationException::withMessages([
                    'status' => 'A '.mb_strtolower($locked->status->label()).' trip cannot be completed.',
                ]);
            }

            $postTrip = $locked->inspectionFor(InspectionPhase::PostTrip);
            $distance = null;

            if ($locked->tracksOdometer()) {
                // The post-trip check is what turns "the driver says it is back"
                // into a record of the condition it came back in.
                if ($postTrip === null) {
                    throw ValidationException::withMessages([
                        'inspection' => 'Record the post-trip vehicle check before closing the trip.',
                    ]);
                }

                $odometer = $validated['odometer_km'] ?? $postTrip->odometer_km;

                if ($odometer === null) {
                    throw ValidationException::withMessages([
                        'odometer_km' => 'Record the closing odometer reading.',
                    ]);
                }

                if ($locked->start_odometer_km !== null && $odometer < $locked->start_odometer_km) {
                    throw ValidationException::withMessages([
                        'odometer_km' => 'The closing reading is below the '
                            .number_format($locked->start_odometer_km).' km recorded on departure.',
                    ]);
                }

                $vehicle = Vehicle::query()
                    ->whereKey($locked->vehicle_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                RecordOdometerReading::advance($vehicle, (int) $odometer);

                $distance = $locked->start_odometer_km === null
                    ? null
                    : (int) $odometer - (int) $locked->start_odometer_km;

                $locked->end_odometer_km = (int) $odometer;
            }

            $locked->forceFill([
                'status' => DriverTripStatus::Completed,
                'completed_at' => now(),
                'end_odometer_km' => $locked->end_odometer_km,
                'distance_km' => $distance,
                'driver_notes' => $validated['driver_notes'] ?? $locked->driver_notes,
            ])->save();

            $this->auditLogger->record(
                event: 'driver_trip.completed',
                auditable: $locked,
                newValues: [
                    'reference' => $locked->reference,
                    'end_odometer_km' => $locked->end_odometer_km,
                    'distance_km' => $locked->distance_km,
                ],
                user: $lockedDriver,
            );

            return $locked->fresh(['vehicle', 'inspections']);
        }, 3);
    }

    /**
     * Abandons a trip that could not be run.
     *
     * Kept distinct from completion so a job that never happened does not
     * appear in distance or utilisation figures as if it had.
     */
    public function abandon(User $driver, DriverTrip $trip, string $reason): DriverTrip
    {
        $reason = trim($reason);

        Validator::make(
            ['reason' => $reason],
            ['reason' => ['required', 'string', 'min:5', 'max:255']],
        )->validate();

        return DB::transaction(function () use ($driver, $trip, $reason): DriverTrip {
            $lockedDriver = DriverAccess::lockedDriver($driver);

            $locked = DriverTrip::query()
                ->whereKey($trip->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $locked->driver_user_id !== (int) $lockedDriver->getKey()) {
                throw new AuthorizationException;
            }

            if (! $locked->canTransitionTo(DriverTripStatus::Abandoned)) {
                throw ValidationException::withMessages([
                    'status' => 'A '.mb_strtolower($locked->status->label()).' trip cannot be abandoned.',
                ]);
            }

            $previous = $locked->status;

            $locked->forceFill([
                'status' => DriverTripStatus::Abandoned,
                'completed_at' => now(),
                'closure_reason' => $reason,
                // Deliberately null: an abandoned job contributed no distance.
                'distance_km' => null,
            ])->save();

            $this->auditLogger->record(
                event: 'driver_trip.abandoned',
                auditable: $locked,
                oldValues: ['status' => $previous->value],
                newValues: ['status' => DriverTripStatus::Abandoned->value],
                user: $lockedDriver,
            );

            return $locked->fresh(['vehicle', 'inspections']);
        }, 3);
    }
}
