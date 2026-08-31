<?php

namespace App\Http\Controllers\Tours;

use App\Enums\TourBookingStatus;
use App\Enums\TourDepartureStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tours\TourCatalogueRequest;
use App\Models\TourCategory;
use App\Models\TourDeparture;
use App\Models\TourPackage;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\View\View;

class TourCatalogueController extends Controller
{
    public function index(TourCatalogueRequest $request): View
    {
        $filters = $request->validated();
        $now = now();
        $holdingStatuses = TourBookingStatus::capacityHoldingValues();
        $departureFrom = filled($filters['date'] ?? null)
            ? CarbonImmutable::parse(
                $filters['date'],
                config('pisfa.business_timezone', 'Africa/Kampala'),
            )->startOfDay()->utc()
            : null;
        $partySize = filled($filters['party_size'] ?? null)
            ? (int) $filters['party_size']
            : null;
        $capacityPlaceholders = implode(',', array_fill(0, count($holdingStatuses), '?'));

        $query = TourPackage::query()
            ->select('tour_packages.*')
            ->published($now)
            ->with([
                'category',
                'coverMedia',
                'departures' => function ($departure) use (
                    $now,
                    $holdingStatuses,
                    $departureFrom,
                    $partySize,
                    $capacityPlaceholders,
                ) {
                    $departure
                        ->where('status', TourDepartureStatus::Scheduled->value)
                        ->where('starts_at', '>', $now)
                        ->when($departureFrom !== null, fn ($query) => $query
                            ->where('starts_at', '>=', $departureFrom))
                        ->when($partySize !== null, fn ($query) => $query
                            ->where('cancellation_cutoff_at', '>', $now)
                            ->whereRaw(
                                "tour_departures.capacity - (select coalesce(sum(tb.traveler_count), 0) from tour_bookings as tb where tb.tour_departure_id = tour_departures.id and tb.status in ({$capacityPlaceholders})) >= ?",
                                [...$holdingStatuses, $partySize],
                            ))
                        ->withSum([
                            'bookings as reserved_seats' => fn ($booking) => $booking
                                ->whereIn('status', $holdingStatuses),
                        ], 'traveler_count')
                        ->orderBy('starts_at');
                },
            ]);

        if (filled($filters['q'] ?? null)) {
            $query->search((string) $filters['q']);
        }

        if (filled($filters['category'] ?? null)) {
            $query->whereHas('category', fn ($category) => $category
                ->where('slug', $filters['category']));
        }

        if ($partySize !== null) {
            $query
                ->where('min_travelers', '<=', $partySize)
                ->where('max_travelers', '>=', $partySize);
        }

        if ($departureFrom !== null || $partySize !== null) {
            $query->whereHas('departures', function ($departure) use (
                $now,
                $departureFrom,
                $partySize,
                $holdingStatuses,
                $capacityPlaceholders,
            ): void {
                $departure
                    ->acceptingBookings($now)
                    ->where('cancellation_cutoff_at', '>', $now)
                    ->when($departureFrom !== null, fn ($candidate) => $candidate
                        ->where('starts_at', '>=', $departureFrom))
                    ->when($partySize !== null, fn ($candidate) => $candidate
                        ->whereRaw(
                            "tour_departures.capacity - (select coalesce(sum(tb.traveler_count), 0) from tour_bookings as tb where tb.tour_departure_id = tour_departures.id and tb.status in ({$capacityPlaceholders})) >= ?",
                            [...$holdingStatuses, $partySize],
                        ));
            });
        }

        if (filled($filters['duration_min'] ?? null)) {
            $query->where('duration_days', '>=', (int) $filters['duration_min']);
        }

        if (filled($filters['duration_max'] ?? null)) {
            $query->where('duration_days', '<=', (int) $filters['duration_max']);
        }

        $currency = strtoupper((string) ($filters['currency'] ?? config('pisfa.currency.default', 'UGX')));
        if (isset($filters['currency']) || isset($filters['min_price']) || isset($filters['max_price'])) {
            $query->where('currency', $currency);
        }

        if (filled($filters['min_price'] ?? null)) {
            $query->where('base_price_minor', '>=', Money::parse((string) $filters['min_price'], $currency));
        }

        if (filled($filters['max_price'] ?? null)) {
            $query->where('base_price_minor', '<=', Money::parse((string) $filters['max_price'], $currency));
        }

        $query->addSelect([
            'next_departure_at' => TourDeparture::query()
                ->select('starts_at')
                ->whereColumn('tour_package_id', 'tour_packages.id')
                ->where('status', TourDepartureStatus::Scheduled->value)
                ->where('starts_at', '>', $now)
                ->where('cancellation_cutoff_at', '>', $now)
                ->when($departureFrom !== null, fn ($departure) => $departure
                    ->where('starts_at', '>=', $departureFrom))
                ->whereRaw(
                    "tour_departures.capacity - (select coalesce(sum(tb.traveler_count), 0) from tour_bookings as tb where tb.tour_departure_id = tour_departures.id and tb.status in ({$capacityPlaceholders})) >= ".($partySize === null ? 'tour_packages.min_travelers' : '?'),
                    $partySize === null ? $holdingStatuses : [...$holdingStatuses, $partySize],
                )
                ->orderBy('starts_at')
                ->limit(1),
        ]);

        match ($filters['sort'] ?? 'recommended') {
            'earliest' => $query->orderByRaw('next_departure_at is null')->orderBy('next_departure_at')->orderBy('id'),
            'price_asc' => $query->orderBy('base_price_minor')->orderBy('id'),
            'newest' => $query->latest('published_at')->latest('id'),
            'duration' => $query->orderBy('duration_days')->orderBy('id'),
            default => $query->orderByDesc('is_featured')->latest('published_at')->latest('id'),
        };

        $packages = $query
            ->paginate((int) ($filters['per_page'] ?? 12))
            ->withQueryString();

        $categories = TourCategory::query()->active()->ordered()->get();

        return view('tours.index', compact('packages', 'categories', 'filters'));
    }

    public function show(string $tourPackage): View
    {
        $now = now();
        $holdingStatuses = TourBookingStatus::capacityHoldingValues();

        $package = TourPackage::query()
            ->published($now)
            ->where('slug', $tourPackage)
            ->with([
                'category',
                'media',
                'itineraryDays',
                'inclusions',
                'exclusions',
                'reviewSummary',
                // Only published reviews, and only the author fields a public
                // page is allowed to render.
                'publishedReviews' => fn ($review) => $review
                    ->with(['customer:id,name', 'repliedBy:id,name'])
                    ->limit(10),
                'departures' => fn ($departure) => $departure
                    ->where('status', TourDepartureStatus::Scheduled->value)
                    ->where('starts_at', '>', $now)
                    ->withSum([
                        'bookings as reserved_seats' => fn ($booking) => $booking
                            ->whereIn('status', $holdingStatuses),
                    ], 'traveler_count')
                    ->orderBy('starts_at'),
            ])
            ->firstOrFail();

        return view('tours.show', compact('package'));
    }
}
