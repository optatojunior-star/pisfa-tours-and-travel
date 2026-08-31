<?php

namespace App\Http\Controllers\Sales;

use App\Actions\Sales\SubmitSalesEnquiry;
use App\Enums\ListingStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\StoreSalesEnquiryRequest;
use App\Models\VehicleListing;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use InvalidArgumentException;

/**
 * The public showroom.
 *
 * Every query goes through the public scope, so a draft or withdrawn listing is
 * never reachable — including by its slug.
 */
class ShowroomController extends Controller
{
    public function index(Request $request): View
    {
        $query = VehicleListing::query()
            ->public()
            ->with('media')
            ->orderByRaw('case when status = ? then 0 else 1 end', [ListingStatus::Available->value])
            ->latest('listed_at');

        // A sold car stays up for a while — recent sales read as a going
        // concern — but not forever, or the showroom looks like it has no stock.
        $soldDays = (int) config('sales.showroom.sold_visible_days', 30);

        $query->where(fn (Builder $nested): Builder => $nested
            ->where('status', '!=', ListingStatus::Sold->value)
            ->orWhere('sold_at', '>=', now()->subDays($soldDays)));

        if (filled($request->query('q'))) {
            $query->search((string) $request->query('q'));
        }

        $currency = strtoupper((string) $request->query('currency', config('pisfa.currency.default', 'UGX')));

        foreach (['min_price' => '>=', 'max_price' => '<='] as $field => $operator) {
            if (blank($request->query($field))) {
                continue;
            }

            try {
                $query->where('currency', $currency)
                    ->where('asking_price_minor', $operator, Money::parse((string) $request->query($field), $currency));
            } catch (InvalidArgumentException) {
                // An unparseable price filter is ignored rather than fatal: the
                // visitor sees the unfiltered showroom, not an error page.
            }
        }

        return view('showroom.index', [
            'listings' => $query->paginate((int) config('sales.showroom.per_page', 12))->withQueryString(),
            'featured' => VehicleListing::query()
                ->available()
                ->featured()
                ->with('media')
                ->latest('listed_at')
                ->limit(3)
                ->get(),
            'search' => $request->query('q'),
            'filters' => [
                'currency' => $currency,
                'min_price' => $request->query('min_price'),
                'max_price' => $request->query('max_price'),
            ],
        ]);
    }

    public function show(string $listing): View
    {
        $vehicle = VehicleListing::query()
            ->public()
            ->where('slug', $listing)
            ->with('media')
            ->firstOrFail();

        return view('showroom.show', [
            'listing' => $vehicle,
            'idempotencyKey' => (string) Str::uuid(),
            'similar' => VehicleListing::query()
                ->available()
                ->whereKeyNot($vehicle->getKey())
                ->where('make', $vehicle->make)
                ->with('media')
                ->limit(3)
                ->get(),
        ]);
    }

    public function enquire(
        StoreSalesEnquiryRequest $request,
        string $listing,
        SubmitSalesEnquiry $action,
    ): RedirectResponse {
        $vehicle = VehicleListing::query()
            ->public()
            ->where('slug', $listing)
            ->firstOrFail();

        $enquiry = $action->execute(
            $request->user(),
            $vehicle,
            $request->validated(),
            (string) $request->validated('idempotency_key'),
        );

        return redirect()
            ->route('showroom.show', $vehicle->slug)
            ->with('success', 'Thank you. Your enquiry reference is '.$enquiry->reference
                .'. A member of the team will be in touch.');
    }
}
