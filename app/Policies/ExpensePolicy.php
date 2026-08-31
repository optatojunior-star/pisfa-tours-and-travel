<?php

namespace App\Policies;

use App\Actions\Finance\FinanceAccess;
use App\Models\Expense;
use App\Models\User;

/**
 * Expense claims.
 *
 * A claimant sees only their own; reviewers see the queue. The split matters
 * because a driver's claims disclose where they were and what they were doing,
 * which is not something colleagues need.
 */
class ExpensePolicy
{
    public function viewAny(User $user): bool
    {
        return FinanceAccess::canClaim($user);
    }

    public function view(User $user, Expense $expense): bool
    {
        return FinanceAccess::canReview($user)
            || (int) $expense->incurred_by_user_id === (int) $user->getKey();
    }

    public function create(User $user): bool
    {
        return FinanceAccess::canClaim($user);
    }

    /** Only before approval, and only by the claimant or a manager. */
    public function update(User $user, Expense $expense): bool
    {
        if (! $expense->status->isEditable()) {
            return false;
        }

        return (int) $expense->incurred_by_user_id === (int) $user->getKey()
            || FinanceAccess::canApprove($user);
    }

    /**
     * Approving, rejecting, and reimbursing.
     *
     * Approving your own claim is refused in the action rather than here, so
     * the person is told why instead of the button silently vanishing.
     */
    public function decide(User $user, Expense $expense): bool
    {
        return FinanceAccess::canApprove($user);
    }
}
