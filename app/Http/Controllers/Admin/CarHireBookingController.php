<?php

namespace App\Http\Controllers\Admin;

use App\Actions\CarHire\AssignCarHireDriver;
use App\Actions\CarHire\ReviewSelfDriveApplication;
use App\Actions\CarHire\TransitionCarHireBooking;
use App\Actions\CarHire\VerifySelfDriveOriginals;
use App\Enums\AccountStatus;
use App\Enums\CarHireBookingStatus;
use App\Enums\SelfDriveApplicationStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AssignCarHireDriverRequest;
use App\Http\Requests\Admin\IndexCarHireBookingsRequest;
use App\Http\Requests\Admin\ReviewSelfDriveApplicationRequest;
use App\Http\Requests\Admin\TransitionCarHireBookingRequest;
use App\Http\Requests\Admin\VerifySelfDriveOriginalsRequest;
use App\Models\CarHireBooking;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class CarHireBookingController extends Controller
{
    public function index(IndexCarHireBookingsRequest $request): View
    {
        $filters = $request->validated();
        $query = CarHireBooking::query()
            ->with(['vehicle.coverMedia', 'customer:id,name,email,phone', 'selfDriveApplication', 'assignedDriver:id,name'])
            ->latest('pickup_at')->latest('id');

        if (filled($filters['q'] ?? null)) {
            $search = trim((string) $filters['q']);
            $query->where(fn (Builder $nested) => $nested
                ->where('reference', 'like', '%'.$search.'%')
                ->orWhere('contact_name', 'like', '%'.$search.'%')
                ->orWhere('contact_email', 'like', '%'.$search.'%')
                ->orWhere('vehicle_name_snapshot', 'like', '%'.$search.'%')
                ->orWhere('registration_plate_snapshot', 'like', '%'.$search.'%'));
        }
        foreach (['status', 'hire_mode'] as $field) {
            if (filled($filters[$field] ?? null)) {
                $query->where($field, $filters[$field]);
            }
        }
        if (filled($filters['from'] ?? null)) {
            $query->where('pickup_at', '>=', CarbonImmutable::parse($filters['from'], config('pisfa.business_timezone'))->startOfDay()->utc());
        }
        if (filled($filters['to'] ?? null)) {
            $query->where('pickup_at', '<', CarbonImmutable::parse($filters['to'], config('pisfa.business_timezone'))->addDay()->startOfDay()->utc());
        }

        $bookings = $query->paginate(20)->withQueryString();

        return view('admin.car-hire-bookings.index', compact('bookings', 'filters'));
    }

    public function show(CarHireBooking $carHireBooking): View
    {
        $this->authorize('view', $carHireBooking);
        $booking = $carHireBooking->load([
            'customer:id,name,email,phone', 'vehicle.media', 'hireRate',
            'selfDriveApplication.reviewedBy:id,name', 'selfDriveApplication.originalsVerifiedBy:id,name',
            'documents.uploadedBy:id,name', 'contracts.acceptedBy:id,name', 'latestNonVoidedContract', 'assignedDriver:id,name,phone',
            'driverAssignments.driver:id,name', 'driverAssignments.assignedBy:id,name', 'driverAssignments.unassignedBy:id,name',
        ]);
        $drivers = User::query()
            ->where('role', UserRole::Driver->value)
            ->where('status', AccountStatus::Active->value)
            ->whereNotNull('email_verified_at')
            ->orderBy('name')->get(['id', 'name', 'phone']);

        return view('admin.car-hire-bookings.show', compact('booking', 'drivers'));
    }

    public function transition(TransitionCarHireBookingRequest $request, CarHireBooking $carHireBooking, TransitionCarHireBooking $action): RedirectResponse
    {
        $action->execute(
            $request->user(),
            $carHireBooking,
            CarHireBookingStatus::from($request->validated('status')),
            $request->validated('reason'),
        );

        return back()->with('success', 'The hire booking status was updated.');
    }

    public function assign(AssignCarHireDriverRequest $request, CarHireBooking $carHireBooking, AssignCarHireDriver $action): RedirectResponse
    {
        $driver = filled($request->validated('driver_user_id'))
            ? User::query()->findOrFail($request->validated('driver_user_id'))
            : null;
        $action->execute($request->user(), $carHireBooking, $driver, $request->validated('reason'));

        return back()->with('success', $driver === null ? 'The driver was released.' : 'The driver assignment was saved.');
    }

    public function review(ReviewSelfDriveApplicationRequest $request, CarHireBooking $carHireBooking, ReviewSelfDriveApplication $action): RedirectResponse
    {
        abort_if($carHireBooking->selfDriveApplication === null, 404);
        $action->execute(
            $request->user(),
            $carHireBooking->selfDriveApplication,
            SelfDriveApplicationStatus::from($request->validated('status')),
            $request->validated('reason'),
            $request->validated('internal_review_notes'),
        );

        return back()->with('success', 'The self-drive application review was recorded.');
    }

    public function verifyOriginals(VerifySelfDriveOriginalsRequest $request, CarHireBooking $carHireBooking, VerifySelfDriveOriginals $action): RedirectResponse
    {
        abort_if($carHireBooking->selfDriveApplication === null, 404);
        $action->execute($request->user(), $carHireBooking->selfDriveApplication);

        return back()->with('success', 'Original identity and driving documents were marked as verified.');
    }
}
