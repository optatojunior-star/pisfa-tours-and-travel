<?php

namespace App\Actions\Fleet;

use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleFuelLog;
use App\Services\AuditLogger;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Records a refuelling against a vehicle.
 *
 * Volume arrives in litres from the form and is stored in whole millilitres, so
 * no float is ever persisted. The odometer goes through the same guard as
 * maintenance, because a fill entered out of order would otherwise move the
 * fleet's mileage backwards and wreck every consumption figure.
 */
class RecordFuelLog
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /** @param array<string, mixed> $attributes */
    public function execute(User $actor, Vehicle $vehicle, array $attributes): VehicleFuelLog
    {
        $input = $this->validated($attributes);

        return DB::transaction(function () use ($actor, $vehicle, $input): VehicleFuelLog {
            $lockedActor = FleetAccess::lockedManager($actor);

            $locked = Vehicle::query()->whereKey($vehicle->getKey())->lockForUpdate()->firstOrFail();

            RecordOdometerReading::advance($locked, $input['odometer_km']);

            $log = new VehicleFuelLog;
            $log->forceFill(array_merge($input, [
                'vehicle_id' => $locked->getKey(),
                'recorded_by_user_id' => $lockedActor->getKey(),
            ]))->save();

            $this->auditLogger->record(
                event: 'vehicle_fuel.recorded',
                auditable: $log,
                newValues: [
                    'vehicle_id' => $locked->getKey(),
                    'odometer_km' => $log->odometer_km,
                    'volume_ml' => $log->volume_ml,
                    'cost_minor' => $log->cost_minor,
                    'currency' => $log->currency,
                ],
                user: $lockedActor,
            );

            return $log->fresh('vehicle');
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function validated(array $attributes): array
    {
        $validated = Validator::make($attributes, [
            'filled_at' => ['required', 'date', 'before_or_equal:now'],
            'odometer_km' => ['required', 'integer', 'min:0', 'max:5000000'],
            // Litres to two decimals, which is what a pump receipt shows.
            'litres' => ['required', 'numeric', 'min:0.01', 'max:2000'],
            'cost' => ['required', 'string', 'max:24'],
            'currency' => ['required', Rule::in(config('pisfa.currency.supported', ['UGX', 'USD']))],
            'station' => ['nullable', 'string', 'max:180'],
            'is_full_tank' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ])->validate();

        $currency = strtoupper((string) $validated['currency']);

        try {
            $cost = Money::parse((string) $validated['cost'], $currency);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['cost' => $exception->getMessage()]);
        }

        // Rounded to whole millilitres at the boundary, so the stored quantity
        // is an integer and every later sum is exact.
        $millilitres = (int) round(((float) $validated['litres']) * VehicleFuelLog::ML_PER_LITRE);

        if ($millilitres < 1) {
            throw ValidationException::withMessages([
                'litres' => 'Enter the volume actually dispensed.',
            ]);
        }

        $notes = isset($validated['notes']) ? trim((string) $validated['notes']) : null;
        $station = isset($validated['station']) ? trim((string) $validated['station']) : null;

        return [
            'filled_at' => CarbonImmutable::parse((string) $validated['filled_at']),
            'odometer_km' => (int) $validated['odometer_km'],
            'volume_ml' => $millilitres,
            'cost_minor' => $cost,
            'currency' => $currency,
            'station' => $station === '' ? null : $station,
            'is_full_tank' => (bool) ($validated['is_full_tank'] ?? true),
            'notes' => $notes === '' ? null : $notes,
        ];
    }
}
