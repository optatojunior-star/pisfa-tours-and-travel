<?php

namespace App\Http\Controllers\CarHire;

use App\Actions\CarHire\AcceptCarHireContract;
use App\Http\Controllers\Controller;
use App\Http\Requests\CarHire\AcceptCarHireContractRequest;
use App\Models\CarHireBooking;
use App\Models\CarHireContract;
use App\Services\Documents\PdfRenderer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class CarHireContractController extends Controller
{
    public function show(CarHireBooking $customerCarHireBooking, CarHireContract $carHireContract): View
    {
        $this->authorize('view', $customerCarHireBooking);
        abort_unless($carHireContract->car_hire_booking_id === $customerCarHireBooking->getKey(), 404);

        return view('car-hire-bookings.contract', ['booking' => $customerCarHireBooking, 'contract' => $carHireContract]);
    }

    /**
     * Streams the contract as a branded PDF.
     *
     * The PDF is rendered on demand from the same immutable snapshot the HTML
     * view uses, so the two can never disagree. It is deliberately not filed as
     * a stored Document here: the contract row is already the versioned record
     * of truth, and persisting a second copy per download would duplicate it.
     */
    public function download(
        Request $request,
        CarHireBooking $customerCarHireBooking,
        CarHireContract $carHireContract,
        PdfRenderer $renderer,
    ): Response {
        $this->authorize('view', $customerCarHireBooking);
        abort_unless($carHireContract->car_hire_booking_id === $customerCarHireBooking->getKey(), 404);

        $pdf = $renderer->render('pdf.car-hire-contract', [
            'contract' => $carHireContract,
            'booking' => $customerCarHireBooking,
        ]);

        $filename = 'pisfa-rental-contract-'.$carHireContract->contract_number.'.pdf';

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ($request->boolean('inline') ? 'inline' : 'attachment')
                .'; filename="'.$filename.'"',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function accept(AcceptCarHireContractRequest $request, CarHireBooking $customerCarHireBooking, CarHireContract $carHireContract, AcceptCarHireContract $action): RedirectResponse
    {
        abort_unless($carHireContract->car_hire_booking_id === $customerCarHireBooking->getKey(), 404);
        $action->execute($request->user(), $customerCarHireBooking, $carHireContract, $request->ip(), $request->userAgent());

        return redirect()->route('portal.car-hire-bookings.contracts.show', [$customerCarHireBooking, $carHireContract])
            ->with('success', 'Your acceptance was recorded against this exact contract version.');
    }
}
