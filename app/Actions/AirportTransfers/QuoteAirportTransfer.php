<?php

namespace App\Actions\AirportTransfers;

use App\Enums\AirportTransferType;
use App\Models\AirportTransferRate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Server-authoritative pricing lookup for the public transfer planner.
 *
 * The planner never trusts a browser-supplied amount. It resolves the same
 * rate version that CreateAirportTransferBooking would select, so the quoted
 * price and the booked price come from one source.
 */
class QuoteAirportTransfer
{
    /**
     * Resolve the effective rate version for every vehicle type on a route.
     *
     * @return Collection<int, AirportTransferRate>
     */
    public function options(
        AirportTransferType $transferType,
        int $airportId,
        int $locationId,
        string $currency,
        CarbonImmutable $serviceStartsAt,
    ): Collection {
        return AirportTransferRate::query()
            ->where('airport_id', $airportId)
            ->where('airport_transfer_location_id', $locationId)
            ->forTransferType($transferType)
            ->forCurrency($currency)
            ->active()
            ->effectiveAt($serviceStartsAt)
            ->orderBy('vehicle_type')
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->get()
            // A vehicle type can hold several historic versions; only the
            // latest effective version prices a new request.
            ->unique('vehicle_type')
            ->values();
    }

    /**
     * Business-timezone service start for a request, mirroring the create action.
     */
    public function serviceStartsAt(
        AirportTransferType $transferType,
        ?string $flightScheduledAt,
        ?string $serviceStartsAt,
    ): ?CarbonImmutable {
        $value = $transferType === AirportTransferType::Pickup
            ? $flightScheduledAt
            : $serviceStartsAt;

        if (! filled($value)) {
            return null;
        }

        $parsed = CarbonImmutable::createFromFormat(
            '!Y-m-d\TH:i',
            (string) $value,
            (string) config('pisfa.business_timezone', 'Africa/Kampala'),
        );

        return $parsed === false ? null : $parsed->utc();
    }
}
