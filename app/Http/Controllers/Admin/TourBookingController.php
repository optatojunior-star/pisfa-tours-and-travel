<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Tours\AssignTourDriver;
use App\Actions\Tours\CancelTourBooking;
use App\Actions\Tours\TransitionTourBooking;
use App\Enums\AccountStatus;
use App\Enums\TourBookingStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AssignTourDriverRequest;
use App\Http\Requests\Admin\IndexTourBookingsRequest;
use App\Http\Requests\Admin\TransitionTourBookingRequest;
use App\Models\TourBooking;
use App\Models\TourPackage;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class TourBookingController extends Controller
{
    public function index(IndexTourBookingsRequest $request): View
    {
        $filters = $request->validated();
        $query = TourBooking::query()
            ->with(['customer:id,name,email,phone', 'tourPackage:id,name,slug', 'assignedDriver:id,name'])
            ->latest('created_at')
            ->latest('id');

        if (filled($filters['q'] ?? null)) {
            $search = trim((string) $filters['q']);
            $query->where(function (Builder $nested) use ($search): void {
                $nested
                    ->where('reference', 'like', '%'.$search.'%')
                    ->orWhere('package_name_snapshot', 'like', '%'.$search.'%')
                    ->orWhereHas('customer', fn (Builder $customer): Builder => $customer
                        ->where(function (Builder $identity) use ($search): void {
                            $identity
                                ->where('name', 'like', '%'.$search.'%')
                                ->orWhere('email', 'like', '%'.$search.'%')
                                ->orWhere('phone', 'like', '%'.$search.'%');
                        }));
            });
        }

        if (filled($filters['status'] ?? null)) {
            $query->where('status', $filters['status']);
        }

        if (filled($filters['package_id'] ?? null)) {
            $query->where('tour_package_id', $filters['package_id']);
        }

        if (filled($filters['from'] ?? null)) {
            $from = CarbonImmutable::parse(
                $filters['from'],
                config('pisfa.business_timezone', 'Africa/Kampala'),
            )->startOfDay()->utc();
            $query->where('departure_starts_at_snapshot', '>=', $from);
        }

        if (filled($filters['to'] ?? null)) {
            $until = CarbonImmutable::parse(
                $filters['to'],
                config('pisfa.business_timezone', 'Africa/Kampala'),
            )->addDay()->startOfDay()->utc();
            $query->where('departure_starts_at_snapshot', '<', $until);
        }

        match ($filters['period'] ?? 'all') {
            'upcoming' => $query->where('departure_starts_at_snapshot', '>=', now()),
            'past' => $query->where('departure_starts_at_snapshot', '<', now()),
            default => null,
        };

        match ($filters['assignment'] ?? null) {
            'assigned' => $query->whereNotNull('assigned_driver_user_id'),
            'unassigned' => $query->whereNull('assigned_driver_user_id'),
            default => null,
        };

        $bookings = $query->paginate(25)->withQueryString();
        $packages = TourPackage::query()->orderBy('name')->get(['id', 'name']);

        return view('admin.tour-bookings.index', compact('bookings', 'packages', 'filters'));
    }

    public function show(TourBooking $tourBooking): View
    {
        $this->authorize('view', $tourBooking);

        $booking = $tourBooking->load([
            'customer:id,name,email,phone',
            'tourPackage.category',
            'departure',
            'travelers',
            'assignedDriver:id,name,email,phone',
            'assignments.driver:id,name,email,phone',
            'assignments.assignedBy:id,name',
            'events',
        ]);

        $drivers = User::query()
            ->where('role', UserRole::Driver->value)
            ->where('status', AccountStatus::Active->value)
            ->whereNotNull('email_verified_at')
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'phone']);

        return view('admin.tour-bookings.show', compact('booking', 'drivers'));
    }

    public function transition(
        TransitionTourBookingRequest $request,
        TourBooking $tourBooking,
        TransitionTourBooking $transitionTourBooking,
        CancelTourBooking $cancelTourBooking,
    ): RedirectResponse {
        $next = TourBookingStatus::from($request->validated('status'));
        $reason = $request->validated('reason');

        if ($next === TourBookingStatus::Cancelled) {
            $cancelTourBooking->execute(
                actor: $request->user(),
                booking: $tourBooking,
                reason: $reason,
            );
        } else {
            $transitionTourBooking->execute(
                actor: $request->user(),
                booking: $tourBooking,
                nextStatus: $next,
                reason: $reason,
            );
        }

        return back()->with('success', "Booking moved to {$next->label()}.");
    }

    public function assign(
        AssignTourDriverRequest $request,
        TourBooking $tourBooking,
        AssignTourDriver $assignTourDriver,
    ): RedirectResponse {
        $driver = filled($request->validated('driver_user_id'))
            ? User::query()->findOrFail($request->integer('driver_user_id'))
            : null;

        $assignTourDriver->execute(
            actor: $request->user(),
            booking: $tourBooking,
            driver: $driver,
            unassignmentReason: $request->validated('reason'),
        );

        return back()->with('success', $driver === null ? 'Driver unassigned.' : "{$driver->name} assigned to this tour.");
    }
}
