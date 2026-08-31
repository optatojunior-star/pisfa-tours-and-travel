<?php

namespace App\Actions\AirportTransfers;

use App\Actions\AirportTransfers\Concerns\InteractsWithAirportTransferDomain;
use App\Models\Airport;
use App\Models\AirportTransferLocation;
use App\Models\AirportTransferRate;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

class ChangeAirportTransferRateStatus
{
    use InteractsWithAirportTransferDomain;

    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function execute(User $actor, AirportTransferRate $rate, bool $isActive): AirportTransferRate
    {
        $this->ensureOperationsActor($actor);

        return DB::transaction(function () use ($actor, $rate, $isActive): AirportTransferRate {
            $lockedActor = User::query()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();
            $this->ensureOperationsActor($lockedActor);

            $airport = Airport::query()->whereKey($rate->airport_id)->lockForUpdate()->firstOrFail();
            $location = AirportTransferLocation::query()
                ->whereKey($rate->airport_transfer_location_id)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedRate = AirportTransferRate::query()
                ->whereKey($rate->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedRate->is_active === $isActive) {
                return $lockedRate;
            }

            if ($isActive && (! $airport->is_active || ! $location->is_active)) {
                $this->invalid('is_active', 'Activate the airport and service location before activating this rate.');
            }

            if ($isActive) {
                $hasOverlap = AirportTransferRate::query()
                    ->where('id', '!=', $lockedRate->getKey())
                    ->where('airport_id', $lockedRate->airport_id)
                    ->where('airport_transfer_location_id', $lockedRate->airport_transfer_location_id)
                    ->where('transfer_type', $lockedRate->transfer_type->value)
                    ->where('vehicle_type', $lockedRate->vehicle_type)
                    ->where('currency', $lockedRate->currency)
                    ->where('is_active', true)
                    ->where('effective_from', '<', $lockedRate->effective_until ?? '9999-12-31 23:59:59')
                    ->where(function ($query) use ($lockedRate): void {
                        $query->whereNull('effective_until')
                            ->orWhere('effective_until', '>', $lockedRate->effective_from);
                    })
                    ->exists();

                if ($hasOverlap) {
                    $this->invalid('is_active', 'This rate overlaps another active matching rate version.');
                }
            }

            $oldStatus = $lockedRate->is_active;
            $lockedRate->forceFill(['is_active' => $isActive])->save();

            $this->auditLogger->record(
                event: 'airport_transfer_rate.status_changed',
                auditable: $lockedRate,
                oldValues: ['is_active' => $oldStatus],
                newValues: ['is_active' => $lockedRate->is_active],
                user: $lockedActor,
            );

            return $lockedRate->load(['airport', 'location']);
        }, 3);
    }
}
