<?php

namespace App\Actions\AirportTransfers;

use App\Actions\AirportTransfers\Concerns\InteractsWithAirportTransferDomain;
use App\Models\Airport;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class SaveAirport
{
    use InteractsWithAirportTransferDomain;

    public function __construct(private readonly AuditLogger $auditLogger) {}

    /** @param array<string, mixed> $attributes */
    public function execute(User $actor, ?Airport $airport, array $attributes): Airport
    {
        $this->ensureOperationsActor($actor);

        $validated = Validator::make($attributes, [
            'code' => [
                'required',
                'string',
                'min:3',
                'max:8',
                'regex:/\A[A-Za-z0-9]+\z/',
                Rule::unique(Airport::class, 'code')->ignore($airport?->getKey()),
            ],
            'name' => ['required', 'string', 'max:180'],
            'city' => ['required', 'string', 'max:120'],
            'country_code' => ['required', 'string', 'size:2', 'alpha'],
            'timezone' => ['required', 'string', Rule::in(timezone_identifiers_list())],
            'terminal_information' => ['nullable', 'string', 'max:5000'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ])->validate();

        return DB::transaction(function () use ($actor, $airport, $validated): Airport {
            $lockedActor = User::query()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();
            $this->ensureOperationsActor($lockedActor);

            $record = $airport === null
                ? new Airport
                : Airport::query()->whereKey($airport->getKey())->lockForUpdate()->firstOrFail();
            $oldValues = $record->exists ? $this->auditValues($record) : [];

            $record->forceFill([
                'code' => strtoupper(trim($validated['code'])),
                'name' => trim($validated['name']),
                'city' => trim($validated['city']),
                'country_code' => strtoupper(trim($validated['country_code'])),
                'timezone' => $validated['timezone'],
                'terminal_information' => $this->nullableString($validated['terminal_information'] ?? null),
                'is_active' => (bool) ($validated['is_active'] ?? true),
                'sort_order' => (int) ($validated['sort_order'] ?? 0),
                'created_by_user_id' => $record->exists
                    ? $record->created_by_user_id
                    : $lockedActor->getKey(),
                'updated_by_user_id' => $lockedActor->getKey(),
            ])->save();

            $this->auditLogger->record(
                event: $airport === null ? 'airport.created' : 'airport.updated',
                auditable: $record,
                oldValues: $oldValues,
                newValues: $this->auditValues($record),
                user: $lockedActor,
            );

            return $record;
        }, 3);
    }

    /** @return array<string, mixed> */
    private function auditValues(Airport $airport): array
    {
        return [
            'code' => $airport->code,
            'name' => $airport->name,
            'city' => $airport->city,
            'country_code' => $airport->country_code,
            'timezone' => $airport->timezone,
            'is_active' => $airport->is_active,
            'sort_order' => $airport->sort_order,
        ];
    }
}
