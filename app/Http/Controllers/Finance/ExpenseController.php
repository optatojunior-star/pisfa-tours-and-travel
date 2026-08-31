<?php

namespace App\Http\Controllers\Finance;

use App\Actions\Finance\SaveExpense;
use App\Actions\Finance\TransitionExpense;
use App\Enums\ExpenseCategory;
use App\Enums\ExpenseStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\SaveExpenseRequest;
use App\Models\Expense;
use App\Models\Vehicle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * A person's own expense claims.
 *
 * Deliberately scoped to the signed-in claimant: a driver's claims say where
 * they were and what they were doing, which colleagues have no need to read.
 */
class ExpenseController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Expense::class);

        $query = Expense::query()
            ->forUser($request->user())
            ->with('vehicle:id,registration_plate')
            ->latest('spent_on')
            ->latest('id');

        $statusInput = $request->query('status');
        $status = is_string($statusInput) ? ExpenseStatus::tryFrom($statusInput) : null;

        if ($status !== null) {
            $query->where('status', $status->value);
        }

        return view('portal.expenses.index', [
            'expenses' => $query->paginate(20)->withQueryString(),
            'status' => $status,
            'categories' => ExpenseCategory::cases(),
            'vehicles' => $this->vehicles(),
        ]);
    }

    public function store(SaveExpenseRequest $request, SaveExpense $action): RedirectResponse
    {
        $vehicleId = $request->validated('vehicle_id');

        $vehicle = $vehicleId === null
            ? null
            : Vehicle::query()->whereKey((int) $vehicleId)->firstOrFail();

        $expense = $action->create($request->user(), $request->validated(), $vehicle);

        return redirect()
            ->route('portal.expenses.index')
            ->with('success', 'Claim saved as a draft. Submit it when the receipt is attached — '
                .'reference '.$expense->reference.'.');
    }

    public function update(
        SaveExpenseRequest $request,
        Expense $expense,
        SaveExpense $action,
    ): RedirectResponse {
        $vehicleId = $request->validated('vehicle_id');

        $vehicle = $vehicleId === null
            ? null
            : Vehicle::query()->whereKey((int) $vehicleId)->firstOrFail();

        $action->update($request->user(), $expense, $request->validated(), $vehicle);

        return back()->with('success', 'Claim updated.');
    }

    public function submit(Request $request, Expense $expense, TransitionExpense $action): RedirectResponse
    {
        $this->authorize('update', $expense);

        $action->submit($request->user(), $expense);

        return back()->with('success', 'Sent for approval.');
    }

    /** @return Collection<int, Vehicle> */
    private function vehicles(): Collection
    {
        return Vehicle::query()
            ->orderBy('registration_plate')
            ->get(['id', 'registration_plate', 'make', 'model']);
    }
}
