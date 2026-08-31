<?php

namespace App\Services\Fleet;

use App\Enums\CarHireBookingStatus;
use App\Enums\DocumentCategory;
use App\Enums\MaintenanceStatus;
use App\Models\Document;
use App\Models\DriverProfile;
use App\Models\Vehicle;
use App\Models\VehicleFuelLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Cost, consumption, utilisation, and compliance figures for the fleet.
 *
 * Money is grouped by currency and never added across UGX and USD: their
 * exponents differ, so one combined figure would be wrong by a factor of a
 * hundred. Consumption is derived from odometer deltas between full tanks,
 * which is the only interval where the measurement is meaningful.
 */
class FleetReport
{
    /**
     * Running costs for one vehicle over a window.
     *
     * @return array<string, mixed>
     */
    public function costsFor(Vehicle $vehicle, ?CarbonImmutable $since = null): array
    {
        $since ??= CarbonImmutable::now()->subMonths(12);

        // Pure aggregates, so they go through the query builder rather than
        // hydrating models with columns that are not theirs.
        $maintenance = DB::table('vehicle_maintenance_records')
            ->where('vehicle_id', $vehicle->getKey())
            ->where('status', MaintenanceStatus::Completed->value)
            ->where('completed_at', '>=', $since)
            ->selectRaw('currency, count(*) as jobs, sum(cost_minor) as total_minor')
            ->groupBy('currency')
            ->get();

        $fuel = DB::table('vehicle_fuel_logs')
            ->where('vehicle_id', $vehicle->getKey())
            ->where('filled_at', '>=', $since)
            ->selectRaw('currency, count(*) as fills, sum(cost_minor) as total_minor, sum(volume_ml) as volume_ml')
            ->groupBy('currency')
            ->get();

        $byCurrency = [];

        foreach ($maintenance as $row) {
            $byCurrency[$row->currency] ??= $this->emptyCostRow();
            $byCurrency[$row->currency]['maintenance_minor'] = (int) $row->total_minor;
            $byCurrency[$row->currency]['maintenance_jobs'] = (int) $row->jobs;
        }

        foreach ($fuel as $row) {
            $byCurrency[$row->currency] ??= $this->emptyCostRow();
            $byCurrency[$row->currency]['fuel_minor'] = (int) $row->total_minor;
            $byCurrency[$row->currency]['fuel_fills'] = (int) $row->fills;
            $byCurrency[$row->currency]['fuel_volume_ml'] = (int) $row->volume_ml;
        }

        foreach ($byCurrency as $currency => $row) {
            $byCurrency[$currency]['total_minor'] = $row['maintenance_minor'] + $row['fuel_minor'];
        }

        ksort($byCurrency);

        return ['since' => $since, 'by_currency' => $byCurrency];
    }

    /**
     * Fuel economy, in litres per 100 km.
     *
     * Only intervals that start and end on a full tank are measurable: a
     * partial fill leaves an unknown amount already in the tank, so including
     * it would report a consumption figure nobody could reproduce.
     *
     * @return array{litres_per_100km: float|null, distance_km: int, volume_ml: int, intervals: int}
     */
    public function consumptionFor(Vehicle $vehicle): array
    {
        $logs = VehicleFuelLog::query()
            ->forVehicle($vehicle)
            ->fullTank()
            ->orderBy('odometer_km')
            ->orderBy('id')
            ->get(['odometer_km', 'volume_ml']);

        if ($logs->count() < 2) {
            return ['litres_per_100km' => null, 'distance_km' => 0, 'volume_ml' => 0, 'intervals' => 0];
        }

        $first = $logs->first();
        $last = $logs->last();
        $distance = (int) $last->odometer_km - (int) $first->odometer_km;

        // The first fill filled the tank for the distance *before* it, which is
        // not in this window, so its volume is excluded.
        $volume = (int) $logs->skip(1)->sum('volume_ml');

        if ($distance < 1 || $volume < 1) {
            return ['litres_per_100km' => null, 'distance_km' => max(0, $distance), 'volume_ml' => $volume, 'intervals' => 0];
        }

        $litres = $volume / VehicleFuelLog::ML_PER_LITRE;

        return [
            'litres_per_100km' => round($litres / $distance * 100, 1),
            'distance_km' => $distance,
            'volume_ml' => $volume,
            'intervals' => $logs->count() - 1,
        ];
    }

    /**
     * How much of a window each vehicle was actually out on hire.
     *
     * Derived from the bookings that hold a vehicle rather than from a separate
     * trip table: a second store of the same fact would drift from the bookings
     * that are the real record.
     *
     * @return array<int, array{days_hired: int, bookings: int, utilisation_percent: int}>
     */
    public function utilisation(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $windowDays = max(1, (int) $from->startOfDay()->diffInDays($to->endOfDay()));
        $holding = CarHireBookingStatus::vehicleHoldingValues();

        $rows = DB::table('car_hire_bookings')
            ->whereIn('status', $holding)
            ->where('pickup_at', '<=', $to->endOfDay()->utc())
            ->where('return_at', '>=', $from->startOfDay()->utc())
            ->selectRaw('vehicle_id, count(*) as bookings, sum(billable_days) as days')
            ->groupBy('vehicle_id')
            ->get();

        $result = [];

        foreach ($rows as $row) {
            $days = (int) $row->days;

            $result[(int) $row->vehicle_id] = [
                'days_hired' => $days,
                'bookings' => (int) $row->bookings,
                // Capped: a booking can overhang the window at either end, and
                // reporting 140% utilisation would be nonsense.
                'utilisation_percent' => min(100, (int) round($days / $windowDays * 100)),
            ];
        }

        return $result;
    }

    /**
     * Vehicle paperwork that has expired or is about to.
     *
     * @return list<array<string, mixed>>
     */
    public function expiringDocuments(
        int $withinDays = 30,
        ?CarbonImmutable $at = null,
        bool $unreportedOnly = false,
    ): array {
        $at ??= CarbonImmutable::now();
        $horizon = $at->addDays($withinDays);

        $query = Document::query()
            ->where('documentable_type', (new Vehicle)->getMorphClass())
            ->where('is_current', true)
            ->whereNotNull('expires_at')
            ->whereDate('expires_at', '<=', $horizon->format('Y-m-d'))
            ->whereIn('category', [
                DocumentCategory::InsuranceDocument->value,
                DocumentCategory::VehicleRegistration->value,
            ]);

        if ($unreportedOnly) {
            // Never warned about, or warned about long enough ago that a
            // still-unrenewed document is worth raising again.
            $repeatAfter = $at->subDays((int) config('fleet.alerts.repeat_after_days', 7));

            $query->where(fn ($nested) => $nested
                ->whereNull('expiry_alert_sent_at')
                ->orWhere('expiry_alert_sent_at', '<=', $repeatAfter));
        }

        $documents = $query
            ->with('documentable')
            ->orderBy('expires_at')
            ->get();

        return $documents->map(fn (Document $document): array => [
            'document' => $document,
            'document_id' => (int) $document->getKey(),
            'vehicle' => $document->documentable,
            'expires_at' => $document->expires_at,
            'has_expired' => $document->expires_at !== null
                && $document->expires_at->endOfDay()->isBefore($at),
        ])->all();
    }

    /**
     * Driver licences that have expired or are about to.
     *
     * The same shape and marker discipline as vehicle paperwork: an unlicensed
     * driver is the people-side of the same compliance problem.
     *
     * @return list<array<string, mixed>>
     */
    public function expiringLicences(
        int $withinDays = 30,
        ?CarbonImmutable $at = null,
        bool $unreportedOnly = false,
    ): array {
        $at ??= CarbonImmutable::now();
        $horizon = $at->addDays($withinDays);

        $query = DriverProfile::query()
            ->whereNotNull('licence_expires_at')
            ->whereDate('licence_expires_at', '<=', $horizon->format('Y-m-d'));

        if ($unreportedOnly) {
            $repeatAfter = $at->subDays((int) config('fleet.alerts.repeat_after_days', 7));

            $query->where(fn ($nested) => $nested
                ->whereNull('expiry_alert_sent_at')
                ->orWhere('expiry_alert_sent_at', '<=', $repeatAfter));
        }

        return $query
            ->with('user:id,name')
            ->orderBy('licence_expires_at')
            ->get()
            ->map(fn (DriverProfile $profile): array => [
                'profile_id' => (int) $profile->getKey(),
                'driver' => $profile->user->name ?? 'Unknown driver',
                'expires_at' => $profile->licence_expires_at,
                'has_expired' => $profile->licenceHasExpired($at),
            ])
            ->all();
    }

    /** @return array<string, int> */
    private function emptyCostRow(): array
    {
        return [
            'maintenance_minor' => 0,
            'maintenance_jobs' => 0,
            'fuel_minor' => 0,
            'fuel_fills' => 0,
            'fuel_volume_ml' => 0,
            'total_minor' => 0,
        ];
    }
}
