<?php

namespace App\Policies;

use App\Actions\Finance\FinanceAccess;
use App\Models\PayrollRun;
use App\Models\User;

/**
 * Payroll.
 *
 * Super-administrator only throughout. A payroll run discloses what every
 * colleague earns, which is a different kind of secret from an approval limit,
 * and one an ordinary manager has no business reading.
 */
class PayrollRunPolicy
{
    public function viewAny(User $user): bool
    {
        return FinanceAccess::canRunPayroll($user);
    }

    public function view(User $user, PayrollRun $run): bool
    {
        return FinanceAccess::canRunPayroll($user);
    }

    public function create(User $user): bool
    {
        return FinanceAccess::canRunPayroll($user);
    }

    /** Adding, changing, and removing lines. */
    public function update(User $user, PayrollRun $run): bool
    {
        return FinanceAccess::canRunPayroll($user) && $run->status->isEditable();
    }

    /** Approving, paying, reopening, cancelling. */
    public function settle(User $user, PayrollRun $run): bool
    {
        return FinanceAccess::canRunPayroll($user);
    }
}
