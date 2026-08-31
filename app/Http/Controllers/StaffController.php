<?php

namespace App\Http\Controllers;

use App\Enums\AccountStatus;
use App\Enums\StaffRoles;
use App\Http\Requests\IndexStaffRequest;
use App\Http\Requests\StoreStaffInvitationRequest;
use App\Http\Requests\UpdateStaffRequest;
use App\Models\StaffInvitation;
use App\Models\User;
use App\Services\StaffInvitationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class StaffController extends Controller
{
    public function __construct(private readonly StaffInvitationService $invitations) {}

    public function index(IndexStaffRequest $request): View
    {
        Gate::authorize('viewAny', User::class);

        $filters = $request->validated();
        $query = User::query()->whereIn('role', StaffRoles::values());

        if (filled($filters['q'] ?? null)) {
            $search = trim((string) $filters['q']);
            $query->where(function ($query) use ($search): void {
                $query->where('name', 'like', '%'.$search.'%')
                    ->orWhere('email', 'like', '%'.$search.'%')
                    ->orWhere('phone', 'like', '%'.$search.'%');
            });
        }

        if (filled($filters['role'] ?? null)) {
            $query->where('role', $filters['role']);
        }

        if (filled($filters['status'] ?? null)) {
            $query->where('status', $filters['status']);
        }

        $staff = $query->orderBy('name')->paginate(15)->withQueryString();
        $latestInvitations = StaffInvitation::query()
            ->whereIn('user_id', $staff->getCollection()->modelKeys())
            ->latest('id')
            ->get()
            ->unique('user_id')
            ->keyBy('user_id');

        return view('staff-index', [
            'staff' => $staff,
            'latestInvitations' => $latestInvitations,
            'roles' => StaffRoles::all(),
            'statuses' => AccountStatus::cases(),
            'filters' => $filters,
        ]);
    }

    public function create(): View
    {
        Gate::authorize('create', User::class);

        return view('staff-create', ['roles' => StaffRoles::all()]);
    }

    public function store(StoreStaffInvitationRequest $request): RedirectResponse
    {
        Gate::authorize('create', User::class);

        $this->invitations->invite(
            actor: $request->user(),
            attributes: $request->safe()->only(['name', 'email', 'phone']),
            role: $request->role(),
            twoFactorRequired: $request->boolean('two_factor_required'),
        );

        return redirect()->route('admin.staff.index')
            ->with('status', 'Invitation sent. The new account stays inactive until it is accepted.');
    }

    public function update(UpdateStaffRequest $request, User $staff): RedirectResponse
    {
        Gate::authorize('update', $staff);

        $this->invitations->updateAccess(
            actor: $request->user(),
            staff: $staff,
            role: $request->role(),
            status: $request->status(),
            twoFactorRequired: $request->boolean('two_factor_required'),
        );

        return back()->with('status', 'Access settings updated for '.$staff->name.'.');
    }

    public function resend(Request $request, User $staff): RedirectResponse
    {
        Gate::authorize('resendInvitation', $staff);

        $this->invitations->resend($request->user(), $staff);

        return back()->with('status', 'A new invitation was sent to '.$staff->email.'.');
    }
}
