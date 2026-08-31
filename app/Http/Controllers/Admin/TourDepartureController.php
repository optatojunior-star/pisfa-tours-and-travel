<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Tours\SaveTourDeparture;
use App\Enums\TourBookingStatus;
use App\Enums\TourDepartureStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ChangeTourDepartureStatusRequest;
use App\Http\Requests\Admin\SaveTourDepartureRequest;
use App\Models\TourDeparture;
use App\Models\TourPackage;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TourDepartureController extends Controller
{
    public function store(
        SaveTourDepartureRequest $request,
        TourPackage $tourPackage,
        SaveTourDeparture $saveTourDeparture,
    ): RedirectResponse {
        $attributes = $request->validated();
        $attributes['status'] = TourDepartureStatus::Scheduled->value;

        $departure = $saveTourDeparture->execute(
            actor: $request->user(),
            package: $tourPackage,
            attributes: $attributes,
        );

        return redirect()
            ->route('admin.tours.show', $tourPackage)
            ->with('success', 'Departure scheduled for '.$departure->starts_at->timezone(config('pisfa.business_timezone'))->format('j M Y, H:i').'.');
    }

    public function update(
        SaveTourDepartureRequest $request,
        TourPackage $tourPackage,
        TourDeparture $tourDeparture,
        SaveTourDeparture $saveTourDeparture,
    ): RedirectResponse {
        abort_unless($tourDeparture->tour_package_id === $tourPackage->getKey(), 404);

        $attributes = $request->validated();

        $saveTourDeparture->execute(
            actor: $request->user(),
            package: $tourPackage,
            attributes: $attributes,
            departure: $tourDeparture,
        );

        return redirect()
            ->route('admin.tours.show', $tourPackage)
            ->with('success', 'Departure updated.');
    }

    public function status(
        ChangeTourDepartureStatusRequest $request,
        TourPackage $tourPackage,
        TourDeparture $tourDeparture,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        abort_unless($tourDeparture->tour_package_id === $tourPackage->getKey(), 404);

        $next = TourDepartureStatus::from($request->validated('status'));

        DB::transaction(function () use ($request, $tourDeparture, $next, $auditLogger): void {
            $departure = TourDeparture::query()->lockForUpdate()->findOrFail($tourDeparture->getKey());
            $current = $departure->status;

            if ($current === $next) {
                return;
            }

            $allowed = match ($current) {
                TourDepartureStatus::Scheduled => [TourDepartureStatus::Closed, TourDepartureStatus::Cancelled, TourDepartureStatus::Completed],
                TourDepartureStatus::Closed => [TourDepartureStatus::Scheduled, TourDepartureStatus::Cancelled, TourDepartureStatus::Completed],
                TourDepartureStatus::Cancelled, TourDepartureStatus::Completed => [],
            };

            if (! in_array($next, $allowed, true)) {
                throw ValidationException::withMessages([
                    'status' => "A {$current->label()} departure cannot move to {$next->label()}.",
                ]);
            }

            if ($next === TourDepartureStatus::Scheduled
                && ($departure->starts_at->isPast() || $departure->cancellation_cutoff_at->isPast())) {
                throw ValidationException::withMessages([
                    'status' => 'This departure is past its booking window and cannot be reopened.',
                ]);
            }

            $heldBookings = $departure->bookings()
                ->whereIn('status', TourBookingStatus::capacityHoldingValues())
                ->count();

            if (in_array($next, [TourDepartureStatus::Cancelled, TourDepartureStatus::Completed], true)
                && $heldBookings > 0) {
                throw ValidationException::withMessages([
                    'status' => 'Resolve every pending or active booking before cancelling or completing this departure.',
                ]);
            }

            if ($next === TourDepartureStatus::Completed && $departure->ends_at->isFuture()) {
                throw ValidationException::withMessages([
                    'status' => 'A departure cannot be completed before its end time.',
                ]);
            }

            $departure->update(['status' => $next]);

            $auditLogger->record(
                event: 'tour_departure.status_changed',
                auditable: $departure,
                oldValues: ['status' => $current->value],
                newValues: [
                    'status' => $next->value,
                    'reason' => $request->validated('reason'),
                ],
                user: $request->user(),
            );
        });

        return back()->with('success', 'Departure status updated.');
    }
}
