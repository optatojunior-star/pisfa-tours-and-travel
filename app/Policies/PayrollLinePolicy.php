<?php

namespace App\Policies;

use App\Actions\Finance\FinanceAccess;
use App\Models\PayrollLine;
use App\Models\User;

/**
 * One person's payslip.
 *
 * The only place outside payroll itself where a salary is readable, and only by
 * the person it belongs to — and only once the run has been approved, because a
 * draft is working that may still change.
 */
class PayrollLinePolicy
{
    public function view(User $user, PayrollLine $line): bool
    {
        if (FinanceAccess::canRunPayroll($user)) {
            return true;
        }

        $line->loadMissing('run');

        return (int) $line->user_id === (int) $user->getKey()
            && ($line->run?->status->isVisibleToEmployee() ?? false);
    }
}
