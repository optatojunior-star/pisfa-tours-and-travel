<?php

namespace App\Http\Controllers\CarHire;

use App\Actions\CarHire\SaveSelfDriveApplication;
use App\Http\Controllers\Controller;
use App\Http\Requests\CarHire\SaveSelfDriveApplicationRequest;
use App\Models\CarHireBooking;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class SelfDriveApplicationController extends Controller
{
    public function edit(CarHireBooking $customerCarHireBooking): View
    {
        $this->authorize('updateSelfDriveApplication', $customerCarHireBooking);
        $booking = $customerCarHireBooking->load(['selfDriveApplication', 'documents']);
        abort_if($booking->selfDriveApplication === null, 404);

        return view('car-hire-bookings.self-drive', compact('booking'));
    }

    public function update(SaveSelfDriveApplicationRequest $request, CarHireBooking $customerCarHireBooking, SaveSelfDriveApplication $action): RedirectResponse
    {
        $action->execute($request->user(), $customerCarHireBooking, $request->validated());

        return back()->with('success', 'Your self-drive application draft was saved.');
    }

    public function submit(SaveSelfDriveApplicationRequest $request, CarHireBooking $customerCarHireBooking, SaveSelfDriveApplication $action): RedirectResponse
    {
        $action->execute($request->user(), $customerCarHireBooking, $request->validated(), true);

        return redirect()->route('portal.car-hire-bookings.show', $customerCarHireBooking)
            ->with('success', 'Your self-drive application was submitted for verification.');
    }
}
