<?php

namespace App\Http\Controllers\FlightInquiries;

use App\Actions\FlightInquiries\CreateFlightInquiry;
use App\Enums\FlightInquiryScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\FlightInquiries\StoreFlightInquiryRequest;
use App\Models\FlightInquiry;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\View\View;

class FlightInquiryController extends Controller
{
    public function create(?string $scope = null): View
    {
        $selectedScope = $scope === null ? null : FlightInquiryScope::tryFrom($scope);

        abort_if($scope !== null && $selectedScope === null, 404);

        $idempotencyKey = (string) Str::uuid();
        $timezone = (string) config('pisfa.business_timezone', 'Africa/Kampala');
        $earliestOutbound = CarbonImmutable::now($timezone)
            ->startOfDay()
            ->addDays((int) config('flight_inquiries.minimum_notice_days', 1));
        $latestOutbound = CarbonImmutable::now($timezone)
            ->startOfDay()
            ->addDays((int) config('flight_inquiries.maximum_advance_days', 365));

        return view('flight-inquiries.create', compact(
            'selectedScope',
            'idempotencyKey',
            'earliestOutbound',
            'latestOutbound',
        ));
    }

    public function store(StoreFlightInquiryRequest $request, CreateFlightInquiry $action): RedirectResponse
    {
        $validated = $request->validated();
        $inquiry = $action->execute($request->user(), $validated, $validated['idempotency_key']);

        $message = 'Flight enquiry received. A travel consultant will send fare options. No seat is held and no payment has been taken.';

        if ($inquiry->isGuest()) {
            return redirect()->to($this->guestTrackingUrl($inquiry))->with('success', $message);
        }

        return redirect()
            ->route('portal.flight-inquiries.show', $inquiry)
            ->with('success', $message);
    }

    public function guest(FlightInquiry $flightInquiry): View
    {
        // Guests have no account, so the temporary signature is the only proof
        // of ownership; an account-owned enquiry belongs in the portal instead.
        abort_unless($flightInquiry->isGuest(), 404);

        $inquiry = $flightInquiry;

        return view('flight-inquiries.guest', compact('inquiry'));
    }

    private function guestTrackingUrl(FlightInquiry $inquiry): string
    {
        return URL::temporarySignedRoute(
            'flight-inquiries.guest.show',
            now()->addHours((int) config('flight_inquiries.guest_tracking.expiry_hours', 168)),
            ['flightInquiry' => $inquiry->reference],
        );
    }
}
