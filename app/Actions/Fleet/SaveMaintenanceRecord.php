<?php

namespace App\Actions\Fleet;

use App\Enums\MaintenanceStatus;
use App\Enums\MaintenanceType;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleMaintenanceRecord;
use App\Services\AuditLogger;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Schedules or edits a piece of maintenance.
 *
 * Cost and odometer are recorded on completion, not here: a scheduled job has
 * neither yet, and inventing them would put fiction into the cost report.
 */
class SaveMaintenanceRecord
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /** @param array<string, mixed> $attributes */
    public function create(User $actor, Vehicle $vehicle, array $attributes): VehicleMaintenanceRecord
    {
        $input = $this->validated($attributes);

        return DB::transaction(function () use ($actor, $vehicle, $input): VehicleMaintenanceRecord {
            $lockedActor = FleetAccess::lockedManager($actor);

            $locked = Vehicle::query()->whereKey($vehicle->getKey())->lockForUpdate()->firstOrFail();

            $record = new VehicleMaintenanceRecord;
            $record->forceFill(array_merge($input, [
                'reference' => 'MNT-'.Str::upper((string) Str::ulid()),
                'vehicle_id' => $locked->getKey(),
                'status' => MaintenanceStatus::Scheduled,
                'recorded_by_user_id' => $lockedActor->getKey(),
            ]))->save();

            $this->auditLogger->record(
                event: 'vehicle_maintenance.scheduled',
                auditable: $record,
                newValues: [
                    'reference' => $record->reference,
                    'vehicle_id' => $locked->getKey(),
                    'type' => $record->type->value,
                    'scheduled_for' => $record->scheduled_for?->toDateString(),
                ],
                user: $lockedActor,
            );

            return $record->fresh('vehicle');
        }, 3);
    }

    /** @param array<string, mixed> $attributes */
    public function update(
        User $actor,
        VehicleMaintenanceRecord $record,
        array $attributes,
    ): VehicleMaintenanceRecord {
        $input = $this->validated($attributes);

        return DB::transaction(function () use ($actor, $record, $input): VehicleMaintenanceRecord {
            $lockedActor = FleetAccess::lockedManager($actor);

            $locked = VehicleMaintenanceRecord::query()
                ->whereKey($record->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            // A closed record is evidence of what happened. Editing it after the
            // fact would rewrite the cost report and the service history.
            if (! $locked->status->isEditable()) {
                throw ValidationException::withMessages([
                    'status' => 'A '.mb_strtolower($locked->status->label())
                        .' maintenance record cannot be edited.',
                ]);
            }

            $locked->forceFill($input)->save();

            $this->auditLogger->record(
                event: 'vehicle_maintenance.updated',
                auditable: $locked,
                newValues: ['reference' => $locked->reference, 'type' => $locked->type->value],
                user: $lockedActor,
            );

            return $locked->fresh('vehicle');
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function validated(array $attributes): array
    {
        $validated = Validator::make($attributes, [
            'type' => ['required', Rule::enum(MaintenanceType::class)],
            'title' => ['required', 'string', 'min:3', 'max:180'],
            'description' => ['nullable', 'string', 'max:5000'],
            'vendor' => ['nullable', 'string', 'max:180'],
            'scheduled_for' => ['nullable', 'date'],
            'currency' => ['required', Rule::in(config('pisfa.currency.supported', ['UGX', 'USD']))],
            'internal_notes' => ['nullable', 'string', 'max:5000'],
        ])->validate();

        return [
            'type' => MaintenanceType::from((string) $validated['type']),
            'title' => trim((string) $validated['title']),
            'description' => $this->nullable($validated['description'] ?? null),
            'vendor' => $this->nullable($validated['vendor'] ?? null),
            'scheduled_for' => $validated['scheduled_for'] ?? null,
            'currency' => strtoupper((string) $validated['currency']),
            'internal_notes' => $this->nullable($validated['internal_notes'] ?? null),
        ];
    }

    /** Parses a money input in the record's own currency. */
    public static function parseCost(string $amount, string $currency): int
    {
        try {
            return Money::parse($amount, $currency);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['cost' => $exception->getMessage()]);
        }
    }

    private function nullable(mixed $value): ?string
    {
        $value = $value === null ? null : trim((string) $value);

        return $value === '' ? null : $value;
    }
}
