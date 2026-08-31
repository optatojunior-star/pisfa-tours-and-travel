<?php

namespace App\Actions\Finance;

use App\Enums\ExpenseStatus;
use App\Models\Expense;
use App\Models\User;
use App\Notifications\Finance\ExpenseDecisionNotification;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Moves a claim from "I spent this" to "we sent you the money".
 *
 * Approving and reimbursing are separate steps on purpose. They are days apart
 * in practice, and somebody who is out of pocket needs to see which of the two
 * has actually happened rather than being told "approved" and left waiting.
 */
class TransitionExpense
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /** The claimant sends it for approval. */
    public function submit(User $actor, Expense $expense): Expense
    {
        return DB::transaction(function () use ($actor, $expense): Expense {
            $lockedActor = FinanceAccess::lockedClaimant($actor);

            $locked = $this->locked($expense);

            if ($locked->status === ExpenseStatus::Submitted) {
                return $locked;
            }

            $this->assertTransition($locked, ExpenseStatus::Submitted);

            if ((int) $locked->incurred_by_user_id !== (int) $lockedActor->getKey()
                && ! FinanceAccess::canApprove($lockedActor)) {
                throw ValidationException::withMessages([
                    'expense' => 'This claim belongs to somebody else.',
                ]);
            }

            $this->apply($locked, ExpenseStatus::Submitted, ['submitted_at' => now()], 'expense.submitted', $lockedActor);

            return $locked->fresh(['vehicle', 'incurredBy']);
        }, 3);
    }

    /**
     * A manager signs it off.
     *
     * `$recoverable` marks vehicle spending that may later be proposed as a
     * deduction against a lease. It is decided here, at approval, because that
     * is the moment somebody with the authority is looking at the receipt.
     */
    public function approve(User $actor, Expense $expense, bool $recoverable = false): Expense
    {
        return DB::transaction(function () use ($actor, $expense, $recoverable): Expense {
            $lockedActor = FinanceAccess::lockedApprover($actor);

            $locked = $this->locked($expense);

            if ($locked->status === ExpenseStatus::Approved) {
                return $locked;
            }

            $this->assertTransition($locked, ExpenseStatus::Approved);

            // Approving your own spending is how expense fraud happens. A
            // second pair of eyes is the entire control.
            if ((int) $locked->incurred_by_user_id === (int) $lockedActor->getKey()) {
                throw ValidationException::withMessages([
                    'expense' => 'Somebody else has to approve your own claim.',
                ]);
            }

            if ($recoverable && ! $this->canBeRecovered($locked)) {
                throw ValidationException::withMessages([
                    'is_recoverable' => 'Only vehicle spending of a recoverable kind can be charged to an owner.',
                ]);
            }

            $this->apply($locked, ExpenseStatus::Approved, [
                'approved_at' => now(),
                'approved_by_user_id' => $lockedActor->getKey(),
                'is_recoverable' => $recoverable,
            ], 'expense.approved', $lockedActor);

            DB::afterCommit(fn () => $this->notify($locked, 'Your claim has been approved.'));

            return $locked->fresh(['vehicle', 'incurredBy']);
        }, 3);
    }

    public function reject(User $actor, Expense $expense, string $reason): Expense
    {
        $reason = $this->validatedReason($reason);

        return DB::transaction(function () use ($actor, $expense, $reason): Expense {
            $lockedActor = FinanceAccess::lockedApprover($actor);

            $locked = $this->locked($expense);

            if ($locked->status === ExpenseStatus::Rejected) {
                return $locked;
            }

            $this->assertTransition($locked, ExpenseStatus::Rejected);

            // A rejected claim that had been marked recoverable must not stay
            // marked, or it would show up in the lease-deduction queue.
            $this->apply($locked, ExpenseStatus::Rejected, [
                'closure_reason' => $reason,
                'is_recoverable' => false,
            ], 'expense.rejected', $lockedActor);

            DB::afterCommit(fn () => $this->notify($locked, 'Your claim was not approved: '.$reason));

            return $locked->fresh(['vehicle', 'incurredBy']);
        }, 3);
    }

    /** Sends it back for a correction, before anything has been signed off. */
    public function returnToDraft(User $actor, Expense $expense, string $reason): Expense
    {
        $reason = $this->validatedReason($reason);

        return DB::transaction(function () use ($actor, $expense, $reason): Expense {
            $lockedActor = FinanceAccess::lockedApprover($actor);

            $locked = $this->locked($expense);

            if ($locked->status === ExpenseStatus::Draft) {
                return $locked;
            }

            $this->assertTransition($locked, ExpenseStatus::Draft);

            $this->apply($locked, ExpenseStatus::Draft, [
                'submitted_at' => null,
                'closure_reason' => $reason,
            ], 'expense.returned', $lockedActor);

            DB::afterCommit(fn () => $this->notify($locked, 'Your claim needs a correction: '.$reason));

            return $locked->fresh(['vehicle', 'incurredBy']);
        }, 3);
    }

    /**
     * Records that the money actually went back.
     *
     * The reference is required: a claim marked reimbursed with nothing to
     * trace it to is indistinguishable from one somebody forgot to pay.
     */
    public function reimburse(User $actor, Expense $expense, string $paymentReference): Expense
    {
        $paymentReference = trim($paymentReference);

        Validator::make(
            ['payment_reference' => $paymentReference],
            ['payment_reference' => ['required', 'string', 'min:3', 'max:120']],
            ['payment_reference.required' => 'Record the transfer reference so the payment can be traced.'],
        )->validate();

        return DB::transaction(function () use ($actor, $expense, $paymentReference): Expense {
            $lockedActor = FinanceAccess::lockedApprover($actor);

            $locked = $this->locked($expense);

            if ($locked->status === ExpenseStatus::Reimbursed) {
                return $locked;
            }

            $this->assertTransition($locked, ExpenseStatus::Reimbursed);

            $this->apply($locked, ExpenseStatus::Reimbursed, [
                'reimbursed_at' => now(),
                'reimbursement_reference' => $paymentReference,
            ], 'expense.reimbursed', $lockedActor);

            DB::afterCommit(fn () => $this->notify($locked, 'Your claim has been reimbursed.'));

            return $locked->fresh(['vehicle', 'incurredBy']);
        }, 3);
    }

    private function canBeRecovered(Expense $expense): bool
    {
        return $expense->vehicle_id !== null && $expense->category->isRecoverableFromOwner();
    }

    /** @param array<string, mixed> $extra */
    private function apply(
        Expense $expense,
        ExpenseStatus $next,
        array $extra,
        string $event,
        User $actor,
    ): void {
        $previous = $expense->status;

        $expense->forceFill(array_merge($extra, ['status' => $next]))->save();

        $this->auditLogger->record(
            event: $event,
            auditable: $expense,
            oldValues: ['status' => $previous->value],
            newValues: [
                'status' => $next->value,
                'amount_minor' => $expense->amount_minor,
                'currency' => $expense->currency,
            ],
            user: $actor,
        );
    }

    private function locked(Expense $expense): Expense
    {
        return Expense::query()
            ->whereKey($expense->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function assertTransition(Expense $expense, ExpenseStatus $next): void
    {
        if (! $expense->canTransitionTo($next)) {
            throw ValidationException::withMessages([
                'status' => "A {$expense->status->label()} claim cannot become {$next->label()}.",
            ]);
        }
    }

    private function validatedReason(string $reason): string
    {
        $reason = trim($reason);

        Validator::make(
            ['reason' => $reason],
            ['reason' => ['required', 'string', 'min:5', 'max:255']],
        )->validate();

        return $reason;
    }

    private function notify(Expense $expense, string $message): void
    {
        $expense->loadMissing('incurredBy');

        $expense->incurredBy?->notify(new ExpenseDecisionNotification(
            reference: $expense->reference,
            description: $expense->description,
            amount: $expense->formattedAmount(),
            statusLabel: $expense->status->label(),
            message: $message,
        ));
    }
}
