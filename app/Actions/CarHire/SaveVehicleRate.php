<?php

namespace App\Actions\CarHire;

use App\Actions\CarHire\Concerns\InteractsWithCarHireDomain;
use App\Enums\VehicleCatalogueStatus;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleHireRate;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class SaveVehicleRate
{
    use InteractsWithCarHireDomain;

    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * Create an immutable rate version. A preceding same-currency version keeps
     * its administrative `is_active` flag and is temporally closed at the new
     * version's start, so a future version never removes today's valid rate.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function execute(User $actor, Vehicle $vehicle, array $attributes): VehicleHireRate
    {
        $this->ensureOperationsActor($actor);
        $validated = Validator::make($attributes, [
            'currency' => ['required', 'string', 'size:3'],
            'self_drive_daily' => ['nullable', 'string', 'max:40'],
            'self_drive_daily_minor' => ['nullable', 'integer', 'min:0'],
            'with_driver_daily' => ['nullable', 'string', 'max:40'],
            'with_driver_daily_minor' => ['nullable', 'integer', 'min:0'],
            'security_deposit' => ['nullable', 'string', 'max:40'],
            'security_deposit_minor' => ['nullable', 'integer', 'min:0'],
            'effective_from' => ['required'],
            'effective_until' => ['nullable'],
            'is_active' => ['sometimes', 'boolean'],
        ])->validate();

        $currency = $this->currency($validated['currency']);
        $selfDriveDailyMinor = $this->money(
            $attributes,
            majorKey: 'self_drive_daily',
            minorKey: 'self_drive_daily_minor',
            currency: $currency,
            nullable: true,
        );
        $withDriverDailyMinor = $this->money(
            $attributes,
            majorKey: 'with_driver_daily',
            minorKey: 'with_driver_daily_minor',
            currency: $currency,
            nullable: true,
        );
        $securityDepositMinor = $this->money(
            $attributes,
            majorKey: 'security_deposit',
            minorKey: 'security_deposit_minor',
            currency: $currency,
            nullable: true,
        ) ?? 0;

        if ($selfDriveDailyMinor === null && $withDriverDailyMinor === null) {
            $this->invalid('self_drive_daily', 'Enter a positive daily rate for at least one hire mode.');
        }

        if ($selfDriveDailyMinor !== null && $selfDriveDailyMinor <= 0) {
            $this->invalid('self_drive_daily', 'The self-drive daily rate must be greater than zero.');
        }

        if ($withDriverDailyMinor !== null && $withDriverDailyMinor <= 0) {
            $this->invalid('with_driver_daily', 'The with-driver daily rate must be greater than zero.');
        }

        if ($selfDriveDailyMinor !== null) {
            $this->assertRentalCalculationFits(
                $selfDriveDailyMinor,
                $securityDepositMinor,
                'self_drive_daily',
            );
        }

        if ($withDriverDailyMinor !== null) {
            $this->assertRentalCalculationFits(
                $withDriverDailyMinor,
                $securityDepositMinor,
                'with_driver_daily',
            );
        }

        $effectiveFrom = $this->utcDateTime($validated['effective_from'], 'effective_from');
        $effectiveUntil = filled($validated['effective_until'] ?? null)
            ? $this->utcDateTime($validated['effective_until'], 'effective_until')
            : null;

        if ($effectiveUntil !== null && ! $effectiveUntil->isAfter($effectiveFrom)) {
            $this->invalid('effective_until', 'The rate end must be after its start.');
        }

        return DB::transaction(function () use (
            $actor,
            $vehicle,
            $validated,
            $currency,
            $selfDriveDailyMinor,
            $withDriverDailyMinor,
            $securityDepositMinor,
            $effectiveFrom,
            $effectiveUntil,
        ): VehicleHireRate {
            $lockedActor = User::query()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();
            $this->ensureOperationsActor($lockedActor);

            // Keep the same actor -> vehicle -> rates lock order as SaveVehicle.
            $lockedVehicle = Vehicle::query()
                ->whereKey($vehicle->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $priorRates = VehicleHireRate::query()
                ->where('vehicle_id', $lockedVehicle->getKey())
                ->where('currency', $currency)
                ->orderBy('effective_from')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $priorRate = $priorRates->last();

            if ($priorRate !== null && ! $effectiveFrom->isAfter($priorRate->effective_from)) {
                $this->invalid(
                    'effective_from',
                    'A new rate version must start after the latest same-currency version.',
                );
            }

            if ($priorRate !== null
                && ($priorRate->effective_until === null || $priorRate->effective_until->isAfter($effectiveFrom))) {
                $oldPriorValues = $this->auditValues($priorRate);
                $priorRate->forceFill(['effective_until' => $effectiveFrom])->save();

                $this->auditLogger->record(
                    event: 'vehicle_hire_rate.closed',
                    auditable: $priorRate,
                    oldValues: $oldPriorValues,
                    newValues: $this->auditValues($priorRate),
                    user: $lockedActor,
                );
            }

            $rate = new VehicleHireRate;
            $rate->forceFill([
                'vehicle_id' => $lockedVehicle->getKey(),
                'currency' => $currency,
                'self_drive_daily_minor' => $selfDriveDailyMinor,
                'with_driver_daily_minor' => $withDriverDailyMinor,
                'security_deposit_minor' => $securityDepositMinor,
                'effective_from' => $effectiveFrom,
                'effective_until' => $effectiveUntil,
                'is_active' => (bool) ($validated['is_active'] ?? true),
                'created_by_user_id' => $lockedActor->getKey(),
            ])->save();

            if ($lockedVehicle->catalogue_status === VehicleCatalogueStatus::Published
                && ! $this->hasCurrentSupportedRate($lockedVehicle)) {
                $this->invalid(
                    'is_active',
                    'A published vehicle must retain a currently effective supported hire rate.',
                );
            }

            $this->auditLogger->record(
                event: 'vehicle_hire_rate.created',
                auditable: $rate,
                oldValues: [],
                newValues: $this->auditValues($rate),
                user: $lockedActor,
            );

            return $rate->load('vehicle');
        }, 3);
    }

    private function hasCurrentSupportedRate(Vehicle $vehicle): bool
    {
        $now = now();

        return VehicleHireRate::query()
            ->where('vehicle_id', $vehicle->getKey())
            ->whereIn('currency', config('car_hire.currencies', ['UGX', 'USD']))
            ->where('is_active', true)
            ->where('effective_from', '<=', $now)
            ->where(function ($query) use ($now): void {
                $query->whereNull('effective_until')->orWhere('effective_until', '>', $now);
            })
            ->where(function ($query): void {
                $query->where('self_drive_daily_minor', '>', 0)
                    ->orWhere('with_driver_daily_minor', '>', 0);
            })
            ->exists();
    }

    /** @return array<string, mixed> */
    private function auditValues(VehicleHireRate $rate): array
    {
        return [
            'vehicle_id' => $rate->vehicle_id,
            'currency' => $rate->currency,
            'self_drive_daily_minor' => $rate->self_drive_daily_minor,
            'with_driver_daily_minor' => $rate->with_driver_daily_minor,
            'security_deposit_minor' => $rate->security_deposit_minor,
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
