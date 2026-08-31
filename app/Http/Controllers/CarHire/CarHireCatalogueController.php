<?php

namespace App\Http\Controllers\CarHire;

use App\Enums\HireMode;
use App\Http\Controllers\Controller;
use App\Http\Requests\CarHire\CarHireCatalogueRequest;
use App\Models\Vehicle;
use App\Models\VehicleHireRate;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\View\View;

class CarHireCatalogueController extends Controller
{
    public function index(CarHireCatalogueRequest $request): View
    {
        $filters = $request->validated();
        [$pickupAt, $returnAt] = $this->hireWindow($filters);
        $pricingAt = $pickupAt ?? CarbonImmutable::now();
        $mode = filled($filters['hire_mode'] ?? null)
            ? HireMode::from($filters['hire_mode'] instanceof HireMode ? $filters['hire_mode']->value : $filters['hire_mode'])
            : null;
        $currency = filled($filters['currency'] ?? null) ? strtoupper((string) $filters['currency']) : null;
        $rateColumn = $mode === HireMode::WithDriver ? 'with_driver_daily_minor' : 'self_drive_daily_minor';

        $rateScope = function (Builder|Relation $rates) use ($pricingAt, $returnAt, $mode, $currency): void {
            $rates
                ->active()
                ->where('effective_from', '<=', $pricingAt)
                ->when($returnAt !== null, fn (Builder $query) => $query
                    ->where(fn (Builder $validity) => $validity
                        ->whereNull('effective_until')
                        ->orWhere('effective_until', '>=', $returnAt)), fn (Builder $query) => $query
                    ->where(fn (Builder $validity) => $validity
                        ->whereNull('effective_until')
                        ->orWhere('effective_until', '>', $pricingAt)))
                ->when($currency !== null, fn (Builder $query) => $query->where('currency', $currency))
                ->when($mode !== null, fn (Builder $query) => $query->whereNotNull(
                    $mode === HireMode::SelfDrive ? 'self_drive_daily_minor' : 'with_driver_daily_minor',
                ))
                ->when($mode === null, fn (Builder $query) => $query
                    ->where(fn (Builder $supported) => $supported
                        ->whereNotNull('self_drive_daily_minor')
                        ->orWhereNotNull('with_driver_daily_minor')))
                ->latest('effective_from')
                ->latest('id');
        };

        $query = Vehicle::query()
            ->select('vehicles.*')
            ->acceptingHire()
            ->whereHas('hireRates', $rateScope)
            ->with(['coverMedia', 'hireRates' => $rateScope]);

        if (filled($filters['q'] ?? null)) {
            $query->search((string) $filters['q']);
        }

        foreach (['vehicle_type', 'transmission'] as $field) {
            if (filled($filters[$field] ?? null)) {
                $query->where($field, $filters[$field]);
            }
        }

        if (filled($filters['min_seats'] ?? null)) {
            $query->where('seating_capacity', '>=', (int) $filters['min_seats']);
        }

        if ($pickupAt !== null && $returnAt !== null) {
            $query->whereDoesntHave('bookings', fn (Builder $bookings) => $bookings
                ->holdingVehicle()
                ->overlapping($pickupAt, $returnAt));
        }

        if ($mode !== null && $currency !== null) {
            if (filled($filters['min_price'] ?? null)) {
                $minimumMinor = Money::parse((string) $filters['min_price'], $currency);
                $query->whereHas('hireRates', function (Builder $rates) use ($rateScope, $rateColumn, $minimumMinor): void {
                    $rateScope($rates);
                    $rates->where($rateColumn, '>=', $minimumMinor);
                });
            }

            if (filled($filters['max_price'] ?? null)) {
                $maximumMinor = Money::parse((string) $filters['max_price'], $currency);
                $query->whereHas('hireRates', function (Builder $rates) use ($rateScope, $rateColumn, $maximumMinor): void {
                    $rateScope($rates);
                    $rates->where($rateColumn, '<=', $maximumMinor);
                });
            }

            if (in_array($filters['sort'] ?? null, ['price_asc', 'price_desc'], true)) {
                $price = VehicleHireRate::query()
                    ->select($rateColumn)
                    ->whereColumn('vehicle_id', 'vehicles.id');
                $rateScope($price);
                $query->addSelect(['catalogue_daily_minor' => $price->limit(1)]);
            }
        }

        match ($filters['sort'] ?? 'recommended') {
            'price_asc' => $query->orderBy('catalogue_daily_minor')->orderBy('id'),
            'price_desc' => $query->orderByDesc('catalogue_daily_minor')->orderBy('id'),
            'newest' => $query->latest('published_at')->latest('id'),
            'seats_desc' => $query->orderByDesc('seating_capacity')->orderBy('id'),
            default => $query->orderByDesc('is_featured')->latest('published_at')->latest('id'),
        };

        $vehicles = $query->paginate((int) ($filters['per_page'] ?? 12))->withQueryString();
        $vehicleTypes = Vehicle::query()->acceptingHire()->distinct()->orderBy('vehicle_type')->pluck('vehicle_type');
        $transmissions = Vehicle::query()->acceptingHire()->distinct()->orderBy('transmission')->pluck('transmission');

        return view('car-hire.index', compact('vehicles', 'vehicleTypes', 'transmissions', 'filters', 'pickupAt', 'returnAt', 'mode'));
    }

    public function show(string $vehicle): View
    {
        $now = now();
        $vehicle = Vehicle::query()
            ->acceptingHire($now)
            ->where('slug', $vehicle)
            ->whereHas('hireRates', fn (Builder $rates) => $rates
                ->active()
                ->effectiveAt($now)
                ->where(fn (Builder $supported) => $supported
                    ->whereNotNull('self_drive_daily_minor')
                    ->orWhereNotNull('with_driver_daily_minor')))
            ->with([
                'media',
                'hireRates' => fn (Builder|Relation $rates) => $rates
                    ->active()
                    ->effectiveAt($now)
                    ->latest('effective_from')
                    ->latest('id'),
            ])
            ->firstOrFail();

        return view('car-hire.show', compact('vehicle'));
    }

    /** @param array<string, mixed> $filters
     * @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable}
     */
    private function hireWindow(array $filters): array
    {
        if (! filled($filters['pickup_at'] ?? null) || ! filled($filters['return_at'] ?? null)) {
            return [null, null];
        }

        $timezone = (string) config('pisfa.business_timezone', 'Africa/Kampala');

        return [
            CarbonImmutable::createFromFormat('!Y-m-d\TH:i', (string) $filters['pickup_at'], $timezone)->utc(),
            CarbonImmutable::createFromFormat('!Y-m-d\TH:i', (string) $filters['return_at'], $timezone)->utc(),
        ];
    }
}
