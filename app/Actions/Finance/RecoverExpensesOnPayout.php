<?php

namespace App\Actions\Finance;

use App\Models\Expense;
use App\Models\User;
use App\Models\VehicleLeasePayout;
use App\Services\AuditLogger;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Charges approved vehicle spending to the owner's statement.
 *
 * This is what closes the loop between the two halves of the finance domain: a
 * repair recorded as an expense becomes a line the owner can read on their
 * payout, rather than a number somebody types in twice and hopes matches.
 *
 * Each expense carries the payout it was recovered on, so the same repair
 * cannot be deducted from an owner in two different months. Reversing the
 * recovery — because the payout was reopened — releases them again.
 */
class RecoverExpensesOnPayout
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * Applies every unrecovered expense for the payout's vehicle and period.
     *
     * Only spending inside the payout's own period is taken: charging January's
     * tyres against March would leave the owner unable to reconcile either
     * month.
     */
    public function execute(User $actor, VehicleLeasePayout $payout): VehicleLeasePayout
    {
        return DB::transaction(function () use ($actor, $payout): VehicleLeasePayout {
            $lockedActor = FinanceAccess::lockedApprover($actor);

            $locked = VehicleLeasePayout::query()
                ->whereKey($payout->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->status->isEditable()) {
                throw ValidationException::withMessages([
                    'status' => 'This statement has already been approved. Figures stop moving at that point.',
                ]);
            }

            $locked->loadMissing('lease');
            $lease = $locked->lease;

            if ($lease === null || $lease->vehicle_id === null) {
                throw ValidationException::withMessages([
                    'lease' => 'This statement has no vehicle to charge spending against.',
                ]);
            }

            $candidates = Expense::query()
                ->where('vehicle_id', $lease->vehicle_id)
                ->awaitingRecovery()
                ->whereDate('spent_on', '>=', $locked->period_start->toDateString())
                ->whereDate('spent_on', '<=', $locked->period_end->toDateString())
                ->lockForUpdate()
                ->get();

            // Money is never summed across currencies: spending in a currency
            // the lease is not denominated in cannot be netted off the owner's
            // payout, and is left for the desk to settle separately.
            $recoverable = $candidates->filter(
                fn (Expense $expense): bool => strtoupper($expense->currency) === strtoupper($locked->currency),
            );

            if ($recoverable->isEmpty()) {
                throw ValidationException::withMessages([
                    'expenses' => 'There is no unrecovered '.$locked->currency
                        .' spending on this vehicle for '.$locked->monthLabel().'.',
                ]);
            }

            $total = (int) $recoverable->sum('amount_minor');

            // A deduction bigger than the earnings would make the owner owe
            // PISFA, which a payout cannot express. The desk applies what fits
            // and carries the rest, rather than the system inventing a debt.
            if ($total > $locked->earned_minor) {
                throw ValidationException::withMessages([
                    'expenses' => 'That spending comes to '.Money::format($total, $locked->currency)
                        .', which is more than the owner earned this period. '
                        .'Recover part of it by hand, or carry it to next month.',
                ]);
            }

            $note = $recoverable->count().' '.str('item')->plural($recoverable->count())
                .' of vehicle spending in '.$locked->monthLabel();

            $locked->forceFill([
                'deductions_minor' => $total,
                'deductions_note' => $note,
                'net_payable_minor' => max(0, $locked->earned_minor - $total),
            ])->save();

            foreach ($recoverable as $expense) {
                $expense->forceFill(['recovered_on_payout_id' => $locked->getKey()])->save();
            }

            $this->auditLogger->record(
                event: 'lease_payout.expenses_recovered',
                auditable: $locked,
                newValues: [
                    'expense_count' => $recoverable->count(),
                    'deductions_minor' => $total,
                    'net_payable_minor' => $locked->net_payable_minor,
                    'currency' => $locked->currency,
                ],
                user: $lockedActor,
            );

            return $locked->fresh('lease');
        }, 3);
    }

    /**
     * Releases everything charged to a payout, so it can be worked out again.
     *
     * Called when a statement is sent back for recalculation: without this the
     * expenses would stay marked as recovered against a figure that no longer
     * exists, and could never be charged to anybody.
     */
    public function release(User $actor, VehicleLeasePayout $payout): int
    {
        return DB::transaction(function () use ($actor, $payout): int {
            $lockedActor = FinanceAccess::lockedApprover($actor);

            $released = Expense::query()
                ->where('recovered_on_payout_id', $payout->getKey())
                ->lockForUpdate()
                ->get();

            foreach ($released as $expense) {
                $expense->forceFill(['recovered_on_payout_id' => null])->save();
            }

            if ($released->isNotEmpty()) {
                $this->auditLogger->record(
                    event: 'lease_payout.expenses_released',
                    auditable: $payout,
                    newValues: ['expense_count' => $released->count()],
                    user: $lockedActor,
                );
            }

            return $released->count();
        }, 3);
    }
}
