<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Finance\BuildPayrollRun;
use App\Actions\Finance\TransitionPayrollRun;
use App\Enums\PayrollRunStatus;
use App\Http\Controllers\Controller;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class PayrollController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', PayrollRun::class);

        $query = PayrollRun::query()
            ->withCount('lines')
            ->latest('period_start')
            ->latest('id');

        $statusInput = $request->query('status');
        $status = is_string($statusInput) ? PayrollRunStatus::tryFrom($statusInput) : null;

        if ($status !== null) {
            $query->where('status', $status->value);
        }

        return view('admin.finance.payroll.index', [
            'runs' => $query->paginate(20)->withQueryString(),
            'status' => $status,
            'currencies' => config('pisfa.currency.supported', ['UGX', 'USD']),
            'defaultCurrency' => config('payroll.default_currency', 'UGX'),
        ]);
    }

    public function store(Request $request, BuildPayrollRun $action): RedirectResponse
    {
        $this->authorize('create', PayrollRun::class);

        $validated = $request->validate([
            'month' => ['required', 'date'],
            'currency' => ['required', 'string', 'size:3'],
        ]);

        $run = $action->open($request->user(), (string) $validated['month'], (string) $validated['currency']);

        return redirect()
            ->route('admin.payroll.show', $run)
            ->with('success', 'Run open for '.$run->monthLabel().'.');
    }

    public function show(PayrollRun $run): View
    {
        $this->authorize('view', $run);

        return view('admin.finance.payroll.show', [
            'run' => $run->load(['lines.deductions', 'lines.employee:id,name,email', 'approvedBy:id,name']),
            'employees' => $this->employees($run),
            'nextStatuses' => $run->status->allowedTransitions(),
        ]);
    }

    public function saveLine(Request $request, PayrollRun $run, BuildPayrollRun $action): RedirectResponse
    {
        $this->authorize('update', $run);

        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'gross' => ['required', 'string', 'max:24'],
            'allowances' => ['nullable', 'string', 'max:24'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'deductions' => ['nullable', 'array', 'max:20'],
        ]);

        $employee = User::query()->whereKey((int) $validated['user_id'])->firstOrFail();

        $action->setLine($request->user(), $run, $employee, array_merge($validated, [
            'currency' => $run->currency,
        ]));

        return back()->with('success', 'Line saved.');
    }

    public function removeLine(
        Request $request,
        PayrollRun $run,
        PayrollLine $line,
        BuildPayrollRun $action,
    ): RedirectResponse {
        $this->authorize('update', $run);

        $action->removeLine($request->user(), $run, $line);

        return back()->with('success', 'Removed from the run.');
    }

    public function approve(Request $request, PayrollRun $run, TransitionPayrollRun $action): RedirectResponse
    {
        $this->authorize('settle', $run);

        $action->approve($request->user(), $run);

        return back()->with('success', 'Approved. Payslips have been filed and everybody has been told.');
    }

    public function reopen(Request $request, PayrollRun $run, TransitionPayrollRun $action): RedirectResponse
    {
        $this->authorize('settle', $run);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ]);

        $action->reopen($request->user(), $run, (string) $validated['reason']);

        return back()->with('success', 'Sent back for correction.');
    }

    public function markPaid(Request $request, PayrollRun $run, TransitionPayrollRun $action): RedirectResponse
    {
        $this->authorize('settle', $run);

        $validated = $request->validate([
            'payment_reference' => ['required', 'string', 'min:3', 'max:120'],
        ]);

        $action->markPaid($request->user(), $run, (string) $validated['payment_reference']);

        return back()->with('success', 'Recorded as paid.');
    }

    public function cancel(Request $request, PayrollRun $run, TransitionPayrollRun $action): RedirectResponse
    {
        $this->authorize('settle', $run);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ]);

        $action->cancel($request->user(), $run, (string) $validated['reason']);

        return back()->with('success', 'Cancelled.');
    }

    /**
     * People who could be added, minus those already on the run.
     *
     * @return Collection<int, User>
     */
    private function employees(PayrollRun $run): Collection
    {
        $already = PayrollLine::query()
            ->where('payroll_run_id', $run->getKey())
            ->pluck('user_id');

        return User::query()
            ->whereIn('role', (array) config('payroll.employee_roles', []))
            ->whereKeyNot($already->all())
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role']);
    }
}
