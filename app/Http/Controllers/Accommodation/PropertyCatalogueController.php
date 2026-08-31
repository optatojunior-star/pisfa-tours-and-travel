<?php

namespace App\Http\Controllers\Accommodation;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\PropertyRoomType;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * The public accommodation catalogue.
 *
 * Every query goes through the published scope, which needs a status *and* a
 * date, so a draft property is unreachable including by its slug.
 */
class PropertyCatalogueController extends Controller
{
    public function index(Request $request): View
    {
        $query = Property::query()
            ->published()
            ->with('media')
            ->withCount(['roomTypes as active_room_types_count' => fn ($types) => $types->active()])
            ->orderByDesc('is_featured')
            ->orderBy('name');

        if (filled($request->query('q'))) {
            $query->search((string) $request->query('q'));
        }

        if (filled($request->query('region'))) {
            $query->inRegion((string) $request->query('region'));
        }

        return view('accommodation.index', [
            'properties' => $query->paginate((int) config('accommodation.catalogue.per_page', 12))->withQueryString(),
            'regions' => Property::query()
                ->published()
                ->distinct()
                ->orderBy('region')
                ->pluck('region'),
            'search' => $request->query('q'),
            'region' => $request->query('region'),
            'currency' => strtoupper((string) $request->query(
                'currency',
                config('pisfa.currency.default', 'UGX'),
            )),
        ]);
    }

    public function show(Request $request, string $property): View
    {
        $found = Property::query()
            ->published()
            ->where('slug', $property)
            ->with(['media', 'roomTypes' => fn ($types) => $types->active(), 'roomTypes.rates'])
            ->firstOrFail();

        $currency = strtoupper((string) $request->query(
            'currency',
            config('pisfa.currency.default', 'UGX'),
        ));

        [$checkIn, $checkOut] = $this->requestedDates($request);

        // Availability is only computed when the visitor has asked about
        // specific dates. Showing a room "available" with no dates attached
        // would be a claim the booking form could not honour.
        $availability = [];

        if ($checkIn !== null && $checkOut !== null) {
            foreach ($found->roomTypes as $roomType) {
                $availability[$roomType->getKey()] = [
                    'rooms' => $roomType->availableRooms($checkIn, $checkOut),
                    'rate' => $roomType->rateFor($checkIn, $checkOut, $currency),
                ];
            }
        }

        return view('accommodation.show', [
            'property' => $found,
            'currency' => $currency,
            'checkIn' => $checkIn,
            'checkOut' => $checkOut,
            'nights' => $checkIn !== null && $checkOut !== null ? (int) $checkIn->diffInDays($checkOut) : null,
            'availability' => $availability,
            'idempotencyKey' => (string) Str::uuid(),
            'reviews' => $found->publishedReviews()->limit(6)->get(),
            'reviewSummary' => $found->reviewSummary,
        ]);
    }

    /**
     * The dates the visitor asked about, or nulls.
     *
     * An unparseable or backwards range is treated as "no dates given" rather
     * than an error page: the property is still worth reading about.
     *
     * @return array{0: CarbonImmutable|null, 1: CarbonImmutable|null}
     */
    private function requestedDates(Request $request): array
    {
        $checkIn = $request->query('check_in_date');
        $checkOut = $request->query('check_out_date');

        if (! is_string($checkIn) || ! is_string($checkOut) || $checkIn === '' || $checkOut === '') {
            return [null, null];
        }

        try {
            $from = CarbonImmutable::parse($checkIn)->startOfDay();
            $to = CarbonImmutable::parse($checkOut)->startOfDay();
        } catch (\Throwable) {
            return [null, null];
        }

        if (! $to->isAfter($from)) {
            return [null, null];
        }

        $maxNights = (int) config('accommodation.max_nights', 60);

        if ($from->diffInDays($to) > $maxNights) {
            return [null, null];
        }

        return [$from, $to];
    }

    /** Rooms of one type still free, as JSON, for the date picker on the page. */
    public function availability(Request $request, string $property, PropertyRoomType $roomType): array
    {
        $found = Property::query()
            ->published()
            ->where('slug', $property)
            ->firstOrFail();

        abort_unless((int) $roomType->property_id === (int) $found->getKey(), 404);

        [$checkIn, $checkOut] = $this->requestedDates($request);

        if ($checkIn === null || $checkOut === null) {
            return ['available' => null, 'message' => 'Choose your dates to see what is free.'];
        }

        $available = $roomType->availableRooms($checkIn, $checkOut);

        return [
            'available' => $available,
            'message' => $available === 0
                ? 'Fully booked for those dates.'
                : $available.' '.str('room')->plural($available).' left for those dates.',
        ];
    }
}
