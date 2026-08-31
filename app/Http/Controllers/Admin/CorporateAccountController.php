<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Corporate\ManageCorporateMembers;
use App\Actions\Corporate\SaveCorporateAccount;
use App\Actions\Corporate\TransitionCorporateAccount;
use App\Enums\CorporateAccountStatus;
use App\Enums\CorporateMemberRole;
use App\Enums\GroupBookingStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveCorporateAccountRequest;
use App\Models\CorporateAccount;
use App\Models\CorporateMember;
use App\Models\GroupBooking;
use App\Models\User;
use App\Services\Corporate\CorporateCreditQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class CorporateAccountController extends Controller
{
    public function index(Request $request, CorporateCreditQuery $credit): View
    {
        $this->authorize('viewAny', CorporateAccount::class);

        $statusInput = $request->query('status');
        $status = is_string($statusInput) ? CorporateAccountStatus::tryFrom($statusInput) : null;

        $query = CorporateAccount::query()
            ->withCount([
                'activeMembers as member_count',
                'groupBookings as open_group_count' => fn ($groups) => $groups->whereIn(
                    'status',
                    GroupBookingStatus::openValues(),
                ),
            ])
            ->orderBy('name');

        if ($status !== null) {
            $query->where('status', $status->value);
        }

        if (filled($request->query('q'))) {
            $query->search((string) $request->query('q'));
        }

        $accounts = $query->paginate(20)->withQueryString();

        // The position is computed per row from live invoices. There is no
        // stored balance to read, and a stored one would be wrong.
        $positions = [];

        foreach ($accounts as $account) {
            $positions[$account->getKey()] = $credit->position($account);
        }

        return view('admin.corporate.index', [
            'accounts' => $accounts,
            'positions' => $positions,
            'status' => $status,
            'search' => $request->query('q'),
            'counts' => $this->counts(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', CorporateAccount::class);

        return view('admin.corporate.create', ['account' => null]);
    }

    public function store(SaveCorporateAccountRequest $request, SaveCorporateAccount $action): RedirectResponse
    {
        $account = $action->create($request->user(), $request->validated());

        return redirect()
            ->route('admin.corporate.show', $account)
            ->with('success', 'Account created as a prospect. Activate it once the terms are signed.');
    }

    public function show(CorporateAccount $account, CorporateCreditQuery $credit): View
    {
        $this->authorize('view', $account);

        return view('admin.corporate.show', [
            'account' => $account->load(['members.user:id,name,email', 'createdBy:id,name']),
            'position' => $credit->position($account),
            'groups' => GroupBooking::query()
                ->where('corporate_account_id', $account->getKey())
                ->with('organiser:id,name')
                ->orderByDesc('starts_on')
                ->limit(10)
                ->get(),
            'nextStatuses' => $account->status->allowedTransitions(),
            'roles' => CorporateMemberRole::cases(),
            'candidates' => $this->candidates($account),
        ]);
    }

    public function edit(CorporateAccount $account): View
    {
        $this->authorize('update', $account);

        return view('admin.corporate.edit', ['account' => $account]);
    }

    public function update(
        SaveCorporateAccountRequest $request,
        CorporateAccount $account,
        SaveCorporateAccount $action,
    ): RedirectResponse {
        $action->updateDetails($request->user(), $account, $request->validated());

        return redirect()
            ->route('admin.corporate.show', $account)
            ->with('success', 'Details updated.');
    }

    public function updateTerms(
        Request $request,
        CorporateAccount $account,
        SaveCorporateAccount $action,
    ): RedirectResponse {
        $this->authorize('setTerms', $account);

        $validated = $request->validate([
            'payment_terms_days' => ['required', 'integer', 'min:0', 'max:180'],
            'credit_limit' => ['required', 'string', 'max:24'],
            'currency' => ['required', 'string', 'size:3'],
            'discount_bps' => ['nullable', 'integer', 'min:0', 'max:'.(int) config('corporate.max_discount_bps', 9900)],
        ]);

        $action->updateTerms($request->user(), $account, $validated);

        return back()->with('success', 'Terms updated.');
    }

    public function activate(Request $request, CorporateAccount $account, TransitionCorporateAccount $action): RedirectResponse
    {
        $this->authorize('transition', $account);

        $action->activate($request->user(), $account);

        return back()->with('success', 'Active. The account can book and be invoiced on terms.');
    }

    public function suspend(Request $request, CorporateAccount $account, TransitionCorporateAccount $action): RedirectResponse
    {
        $this->authorize('transition', $account);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ]);

        $action->suspend($request->user(), $account, (string) $validated['reason']);

        return back()->with('success', 'Suspended. No new bookings on credit; the invoices already out are unaffected.');
    }

    public function close(Request $request, CorporateAccount $account, TransitionCorporateAccount $action): RedirectResponse
    {
        $this->authorize('transition', $account);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ]);

        $action->close($request->user(), $account, (string) $validated['reason']);

        return back()->with('success', 'Closed.');
    }

    public function reopen(Request $request, CorporateAccount $account, TransitionCorporateAccount $action): RedirectResponse
    {
        $this->authorize('transition', $account);

        $action->reopen($request->user(), $account);

        return back()->with('success', 'Reopened as a prospect. Agree the terms again before activating it.');
    }

    public function addMember(
        Request $request,
        CorporateAccount $account,
        ManageCorporateMembers $action,
    ): RedirectResponse {
        $this->authorize('manageMembers', $account);

        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'role' => ['required', 'string'],
            'job_title' => ['nullable', 'string', 'max:120'],
        ]);

        $role = CorporateMemberRole::tryFrom((string) $validated['role']);

        abort_if($role === null, 404);

        $person = User::query()->whereKey((int) $validated['user_id'])->firstOrFail();

        $action->add($request->user(), $account, $person, $role, $validated['job_title'] ?? null);

        return back()->with('success', $person->name.' is on the account as a '
            .mb_strtolower($role->label()).'.');
    }

    public function removeMember(
        Request $request,
        CorporateAccount $account,
        CorporateMember $member,
        ManageCorporateMembers $action,
    ): RedirectResponse {
        $this->authorize('manageMembers', $account);

        $action->deactivate($request->user(), $account, $member);

        return back()->with('success', 'Access revoked. Their bookings are unaffected.');
    }

    /**
     * Customers who could be added, minus those already on the account.
     *
     * @return Collection<int, User>
     */
    private function candidates(CorporateAccount $account): Collection
    {
        $already = CorporateMember::query()
            ->where('corporate_account_id', $account->getKey())
            ->where('is_active', true)
            ->pluck('user_id');

        return User::query()
            ->where('role', UserRole::Customer->value)
            ->whereKeyNot($already->all())
            ->orderBy('name')
            ->limit(500)
            ->get(['id', 'name', 'email']);
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        $counts = [];

        foreach (CorporateAccountStatus::cases() as $case) {
            $counts[$case->value] = CorporateAccount::query()->where('status', $case->value)->count();
        }

        return $counts;
    }
}
