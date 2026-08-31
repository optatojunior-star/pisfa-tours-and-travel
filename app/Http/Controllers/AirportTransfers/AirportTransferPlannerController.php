<?php

namespace App\Http\Controllers\AirportTransfers;

use App\Actions\AirportTransfers\CreateAirportTransferBooking;
use App\Actions\AirportTransfers\QuoteAirportTransfer;
use App\Enums\AirportTransferType;
use App\Http\Controllers\Controller;
use App\Http\Requests\AirportTransfers\AirportTransferQuoteRequest;
use App\Http\Requests\AirportTransfers\StoreAirportTransferBookingRequest;
use App\Models\Airport;
use App\Models\AirportTransferBooking;
use App\Models\AirportTransferLocation;
use App\Models\AirportTransferRate;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\View\View;

class AirportTransferPlannerController extends Controller
{
    public function index(AirportTransferQuoteRequest $request, QuoteAirportTransfer $quote): View
    {
        $filters = $request->validated();
        $airports = Airport::query()->active()->ordered()->get(['id', 'code', 'name', 'city', 'terminal_information']);
        $locations = AirportTransferLocation::query()->active()->ordered()->get(['id', 'slug', 'name', 'region', 'description']);

        $options = collect();
        $serviceStartsAt = null;
        $transferType = filled($filters['transfer_type'] ?? null)
            ? AirportTransferType::from((string) $filters['transfer_type'])
            : null;

        if ($transferType !== null
            && filled($filters['airport_id'] ?? null)
            && filled($filters['airport_transfer_location_id'] ?? null)
            && filled($filters['currency'] ?? null)) {
            $serviceStartsAt = $quote->serviceStartsAt(
                $transferType,
                $filters['flight_scheduled_at'] ?? null,
                $filters['service_starts_at'] ?? null,
            );

            if ($serviceStartsAt !== null) {
                $options = $this->affordableOptions(
                    $quote->options(
                        $transferType,
                        (int) $filters['airport_id'],
                        (int) $filters['airport_transfer_location_id'],
                        (string) $filters['currency'],
                        $serviceStartsAt,
                    ),
                    (int) ($filters['passenger_count'] ?? 1),
                    (int) ($filters['luggage_count'] ?? 0),
                );
            }
        }

        $idempotencyKey = (string) Str::uuid();
        $earliestStart = CarbonImmutable::now(config('pisfa.business_timezone', 'Africa/Kampala'))
            ->addHours((int) config('airport_transfers.minimum_notice_hours', 2));

        return view('airport-transfers.index', compact(
            'airports',
            'locations',
            'filters',
            'options',
            'serviceStartsAt',
            'transferType',
            'idempotencyKey',
            'earliestStart',
        ));
    }

    public function store(
        StoreAirportTransferBookingRequest $request,
        CreateAirportTransferBooking $action,
    ): RedirectResponse {
        $validated = $request->validated();
        $airport = Airport::query()->active()->findOrFail($validated['airport_id']);
        $location = AirportTransferLocation::query()
            ->active()
            ->findOrFail($validated['airport_transfer_location_id']);

        $booking = $action->execute(
            $request->user(),
            $airport,
            $location,
            $validated,
            $validated['idempotency_key'],
        );

        $message = 'Transfer request received. No payment has been taken. PISFA will confirm a vehicle and driver and contact you.';

        if ($booking->isGuest()) {
            return redirect()
                ->to($this->guestConfirmationUrl($booking))
                ->with('success', $message);
        }

        return redirect()
            ->route('portal.airport-transfer-bookings.show', $booking)
            ->with('success', $message);
    }

    public function guest(AirportTransferBooking $airportTransferBooking): View
    {
        // Reached only through a temporary signed URL; a guest request has no
        // account to authorize against, so ownership is the signature itself.
        abort_unless($airportTransferBooking->isGuest(), 404);

        $booking = $airportTransferBooking->load(['airport', 'location', 'rate']);

        return view('airport-transfers.guest', compact('booking'));
    }

    /**
     * @param  Collection<int, AirportTransferRate>  $options
     * @return Collection<int, AirportTransferRate>
     */
    private function affordableOptions(Collection $options, int $passengers, int $luggage): Collection
    {
        return $options
            ->filter(fn (AirportTransferRate $rate): bool => $rate->supportsParty(
                max(1, $passengers),
                max(0, $luggage),
            ))
            ->values();
    }

    private function guestConfirmationUrl(AirportTransferBooking $booking): string
    {
        return URL::temporarySignedRoute(
            'airport-transfer-bookings.guest.show',
            now()->addHours((int) config('airport_transfers.guest_confirmation.expiry_hours', 168)),
            ['airportTransferBooking' => $booking->reference],
        );
    }
}
