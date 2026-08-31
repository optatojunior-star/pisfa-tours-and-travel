<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Finance\TransitionExpense;
use App\Enums\ExpenseCategory;
use App\Enums\ExpenseStatus;
use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ExpenseReviewController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Expense::class);

        abort_unless($request->user()?->can('decide', new Expense) ?? false, 403);

        $statusInput = $request->query('status');
        $status = is_string($statusInput) ? ExpenseStatus::tryFrom($statusInput) : null;

        $categoryInput = $request->query('category');
        $category = is_string($categoryInput) ? ExpenseCategory::tryFrom($categoryInput) : null;

        $query = Expense::query()
            ->with(['incurredBy:id,name', 'vehicle:id,registration_plate'])
            ->latest('spent_on')
            ->latest('id');

        if ($status !== null) {
            $query->where('status', $status->value);
        } elseif ($request->query('show') !== 'all') {
            // The queue opens on what needs a decision.
            $query->where('status', ExpenseStatus::Submitted->value);
        }

        if ($category !== null) {
            $query->where('category', $category->value);
        }

        if (filled($request->query('q'))) {
            $query->search((string) $request->query('q'));
        }

        return view('admin.finance.expenses.index', [
            'expenses' => $query->paginate(25)->withQueryString(),
            'status' => $status,
            'category' => $category,
            'search' => $request->query('q'),
            'showAll' => $request->query('show') === 'all',
            'counts' => $this->counts(),
            'totals' => $this->totals(),
        ]);
    }

    public function show(Expense $expense): View
    {
        $this->authorize('view', $expense);

        return view('admin.finance.expenses.show', [
            'expense' => $expense->load(['incurredBy:id,name,email', 'approvedBy:id,name', 'vehicle', 'receipts', 'recoveredOnPayout.lease']),
            'nextStatuses' => $expense->status->allowedTransitions(),
        ]);
    }

    public function approve(Request $request, Expense $expense, TransitionExpense $action): RedirectResponse
    {
        $this->authorize('decide', $expense);

        $validated = $request->validate([
            'is_recoverable' => ['nullable', 'boolean'],
        ]);

        $action->approve($request->user(), $expense, (bool) ($validated['is_recoverable'] ?? false));

        return back()->with('success', 'Approved.');
    }

    public function reject(Request $request, Expense $expense, TransitionExpense $action): RedirectResponse
    {
        $this->authorize('decide', $expense);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ]);

        $action->reject($request->user(), $expense, (string) $validated['reason']);

        return back()->with('success', 'Rejected, and the claimant has been told why.');
    }

    public function returnToDraft(Request $request, Expense $expense, TransitionExpense $action): RedirectResponse
    {
        $this->authorize('decide', $expense);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ]);

        $action->returnToDraft($request->user(), $expense, (string) $validated['reason']);

        return back()->with('success', 'Sent back for a correction.');
    }

    public function reimburse(Request $request, Expense $expense, TransitionExpense $action): RedirectResponse
    {
        $this->authorize('decide', $expense);

        $validated = $request->validate([
            'reimbursement_reference' => ['required', 'string', 'min:3', 'max:120'],
        ]);

        $action->reimburse($request->user(), $expense, (string) $validated['reimbursement_reference']);

        return back()->with('success', 'Recorded as reimbursed.');
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        $counts = [];

        foreach (ExpenseStatus::cases() as $case) {
            $counts[$case->value] = Expense::query()->where('status', $case->value)->count();
        }

        $counts['awaiting_recovery'] = Expense::query()->awaitingRecovery()->count();

        return $counts;
    }

    /**
     * Approved spend this month, per currency.
     *
     * Kept as separate rows rather than one figure, because money is never
     * summed across currencies — a single total would be a lie.
     *
     * @return array<string, string>
     */
    private function totals(): array
    {
        // Raw query builder rather than Eloquent: the projection is two
        // aggregate columns, not an Expense, and hydrating models to sum them
        // would only invite the result to be mistaken for one.
        $rows = DB::table('expenses')
            ->whereIn('status', ExpenseStatus::spendValues())
            ->whereDate('spent_on', '>=', now()->startOfMonth()->toDateString())
            ->selectRaw('currency, sum(amount_minor) as total')
            ->groupBy('currency')
            ->get();

        $totals = [];

        foreach ($rows as $row) {
            $totals[(string) $row->currency] = Money::format((int) $row->total, (string) $row->currency);
        }

        return $totals;
    }
}
