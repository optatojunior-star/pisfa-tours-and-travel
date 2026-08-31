<?php

namespace App\Actions\AirportTransfers;

use App\Actions\AirportTransfers\Concerns\InteractsWithAirportTransferDomain;
use App\Models\AirportTransferLocation;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class SaveAirportTransferLocation
{
    use InteractsWithAirportTransferDomain;

    public function __construct(private readonly AuditLogger $auditLogger) {}

    /** @param array<string, mixed> $attributes */
    public function execute(
        User $actor,
        ?AirportTransferLocation $location,
        array $attributes,
    ): AirportTransferLocation {
        $this->ensureOperationsActor($actor);

        $validated = Validator::make($attributes, [
            'slug' => [
                'required',
                'string',
                'max:200',
                'regex:/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/',
                Rule::unique(AirportTransferLocation::class, 'slug')->ignore($location?->getKey()),
            ],
            'name' => ['required', 'string', 'max:180'],
            'region' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:5000'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ])->validate();

        return DB::transaction(function () use ($actor, $location, $validated): AirportTransferLocation {
            $lockedActor = User::query()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();
            $this->ensureOperationsActor($lockedActor);

            $record = $location === null
                ? new AirportTransferLocation
                : AirportTransferLocation::query()->whereKey($location->getKey())->lockForUpdate()->firstOrFail();
            $oldValues = $record->exists ? $this->auditValues($record) : [];

            $record->forceFill([
                'slug' => $validated['slug'],
                'name' => trim($validated['name']),
                'region' => trim($validated['region']),
                'description' => $this->nullableString($validated['description'] ?? null),
                'is_active' => (bool) ($validated['is_active'] ?? true),
                'sort_order' => (int) ($validated['sort_order'] ?? 0),
                'created_by_user_id' => $record->exists
                    ? $record->created_by_user_id
                    : $lockedActor->getKey(),
                'updated_by_user_id' => $lockedActor->getKey(),
            ])->save();

            $this->auditLogger->record(
                event: $location === null
                    ? 'airport_transfer_location.created'
                    : 'airport_transfer_location.updated',
                auditable: $record,
                oldValues: $oldValues,
                newValues: $this->auditValues($record),
                user: $lockedActor,
            );

            return $record;
        }, 3);
    }

    /** @return array<string, mixed> */
    private function auditValues(AirportTransferLocation $location): array
    {
        return [
            'slug' => $location->slug,
            'name' => $location->name,
            'region' => $location->region,
            'is_active' => $location->is_active,
            'sort_order' => $location->sort_order,
        ];
    }
}
