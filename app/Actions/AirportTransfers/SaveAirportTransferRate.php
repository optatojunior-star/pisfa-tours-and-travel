<?php

namespace App\Actions\AirportTransfers;

use App\Actions\AirportTransfers\Concerns\InteractsWithAirportTransferDomain;
use App\Enums\AirportTransferType;
use App\Models\Airport;
use App\Models\AirportTransferLocation;
use App\Models\AirportTransferRate;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class SaveAirportTransferRate
{
    use InteractsWithAirportTransferDomain;

    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * Append an immutable rate version. A later version closes the previous
     * matching interval; bookings retain their selected rate and snapshots.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function execute(
        User $actor,
        Airport $airport,
        AirportTransferLocation $location,
        array $attributes,
    ): AirportTransferRate {
        $this->ensureOperationsActor($actor);

        $validated = Validator::make($attributes, [
            'transfer_type' => ['required', Rule::enum(AirportTransferType::class)],
            'vehicle_type' => ['required', 'string', 'max:40'],
            'currency' => ['required', 'string', 'size:3'],
            'passenger_capacity' => [
                'required',
                'integer',
                'min:1',
                'max:'.(int) config('airport_transfers.maximum_passengers', 50),
            ],
            'luggage_capacity' => [
                'required',
                'integer',
                'min:0',
                'max:'.(int) config('airport_transfers.maximum_luggage', 100),
            ],
            'amount' => ['nullable', 'string', 'max:40'],
            'amount_minor' => ['nullable', 'integer', 'min:0'],
            'estimated_duration_minutes' => [
                'required',
                'integer',
                'min:'.(int) config('airport_transfers.rate_duration.minimum_minutes', 15),
                'max:'.(int) config('airport_transfers.rate_duration.maximum_minutes', 1440),
            ],
            'effective_from' => ['required'],
            'effective_until' => ['nullable'],
            'is_active' => ['sometimes', 'boolean'],
        ])->validate();

        $transferType = $validated['transfer_type'] instanceof AirportTransferType
            ? $validated['transfer_type']
            : AirportTransferType::from($validated['transfer_type']);
        $vehicleType = trim($validated['vehicle_type']);
        $currency = $this->currency($validated['currency']);
        $amountMinor = $this->money($attributes, 'amount', 'amount_minor', $currency);
        $effectiveFrom = $this->utcDateTime($validated['effective_from'], 'effective_from');
        $effectiveUntil = filled($validated['effective_until'] ?? null)
            ? $this->utcDateTime($validated['effective_until'], 'effective_until')
            : null;

        if ($amountMinor < 1) {
            $this->invalid('amount', 'The transfer amount must be greater than zero.');
        }

        if ($effectiveUntil !== null && ! $effectiveUntil->isAfter($effectiveFrom)) {
            $this->invalid('effective_until', 'The rate end must be after its start.');
        }

        return DB::transaction(function () use (
            $actor,
            $airport,
            $location,
            $validated,
            $transferType,
            $vehicleType,
            $currency,
            $amountMinor,
            $effectiveFrom,
            $effectiveUntil,
        ): AirportTransferRate {
            $lockedActor = User::query()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();
            $this->ensureOperationsActor($lockedActor);

            $lockedAirport = Airport::query()->whereKey($airport->getKey())->lockForUpdate()->firstOrFail();
            $lockedLocation = AirportTransferLocation::query()
                ->whereKey($location->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $lockedAirport->is_active) {
                $this->invalid('airport', 'Activate the airport before adding an active transfer rate.');
            }

            if (! $lockedLocation->is_active) {
                $this->invalid('location', 'Activate the service location before adding an active transfer rate.');
            }

            $versions = AirportTransferRate::query()
                ->where('airport_id', $lockedAirport->getKey())
                ->where('airport_transfer_location_id', $lockedLocation->getKey())
                ->where('transfer_type', $transferType->value)
                ->where('vehicle_type', $vehicleType)
                ->where('currency', $currency)
                ->orderBy('effective_from')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $latest = $versions->last();

            if ($latest !== null && ! $effectiveFrom->isAfter($latest->effective_from)) {
                $this->invalid(
                    'effective_from',
                    'A new rate version must start after the latest matching version.',
                );
            }

            if ($latest !== null
                && ($latest->effective_until === null || $latest->effective_until->isAfter($effectiveFrom))) {
                $oldLatest = $this->auditValues($latest);
                $latest->forceFill(['effective_until' => $effectiveFrom])->save();

                $this->auditLogger->record(
                    event: 'airport_transfer_rate.closed',
                    auditable: $latest,
                    oldValues: $oldLatest,
                    newValues: $this->auditValues($latest),
                    user: $lockedActor,
                );
            }

            $rate = new AirportTransferRate;
            $rate->forceFill([
                'airport_id' => $lockedAirport->getKey(),
                'airport_transfer_location_id' => $lockedLocation->getKey(),
                'transfer_type' => $transferType,
                'vehicle_type' => $vehicleType,
                'currency' => $currency,
                'passenger_capacity' => (int) $validated['passenger_capacity'],
                'luggage_capacity' => (int) $validated['luggage_capacity'],
                'amount_minor' => $amountMinor,
                'estimated_duration_minutes' => (int) $validated['estimated_duration_minutes'],
                'effective_from' => $effectiveFrom,
                'effective_until' => $effectiveUntil,
                'is_active' => (bool) ($validated['is_active'] ?? true),
                'created_by_user_id' => $lockedActor->getKey(),
            ])->save();

            $this->auditLogger->record(
                event: 'airport_transfer_rate.created',
                auditable: $rate,
                newValues: $this->auditValues($rate),
                user: $lockedActor,
            );

            return $rate->load(['airport', 'location']);
        }, 3);
    }

    /** @return array<string, mixed> */
    private function auditValues(AirportTransferRate $rate): array
    {
        return [
            'airport_id' => $rate->airport_id,
            'airport_transfer_location_id' => $rate->airport_transfer_location_id,
            'transfer_type' => $rate->transfer_type instanceof AirportTransferType
                ? $rate->transfer_type->value
                : $rate->transfer_type,
            'vehicle_type' => $rate->vehicle_type,
            'currency' => $rate->currency,
            'passenger_capacity' => $rate->passenger_capacity,
            'luggage_capacity' => $rate->luggage_capacity,
            'amount_minor' => $rate->amount_minor,
            'estimated_duration_minutes' => $rate->estimated_duration_minutes,
            'effective_from' => $this->isoDateTime($rate->effective_from),
            'effective_until' => $this->isoDateTime($rate->effective_until),
            'is_active' => $rate->is_active,
        ];
    }

    private function isoDateTime(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value instanceof CarbonImmutable
            ? $value->toIso8601String()
            : CarbonImmutable::instance($value)->toIso8601String();
    }
}
