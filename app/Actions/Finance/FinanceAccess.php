<?php

namespace App\Actions\Finance;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Who may spend, approve, and run payroll.
 *
 * Three tiers rather than two, because the domain genuinely has three: a driver
 * claims what they spent, a manager approves it, and payroll — which exposes
 * what every colleague earns — sits above both.
 */
final class FinanceAccess
{
    /** Anybody on the payroll may claim what they spent. */
    public static function canClaim(?User $actor): bool
    {
        return $actor !== null
            && $actor->status === AccountStatus::Active
            && $actor->hasAnyRole(UserRole::Staff, UserRole::Manager, UserRole::SuperAdmin, UserRole::Driver);
    }

    /** Reading the expense console and working the queue. */
    public static function canReview(?User $actor): bool
    {
        return $actor !== null
            && $actor->status === AccountStatus::Active
            && $actor->hasAnyRole(UserRole::Staff, UserRole::Manager, UserRole::SuperAdmin);
    }

    /** Approving spending and releasing reimbursements. */
    public static function canApprove(?User $actor): bool
    {
        return $actor !== null
            && $actor->status === AccountStatus::Active
            && $actor->hasAnyRole(UserRole::Manager, UserRole::SuperAdmin);
    }

    /**
     * Payroll.
     *
     * Restricted to super administrators: a payroll run discloses what every
     * colleague earns, which is a different kind of secret from an approval
     * limit, and one an ordinary manager has no business reading.
     */
    public static function canRunPayroll(?User $actor): bool
    {
        return $actor !== null
            && $actor->status === AccountStatus::Active
            && $actor->hasRole(UserRole::SuperAdmin);
    }

    public static function lockedClaimant(User $actor): User
    {
        $locked = User::query()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();

        if (! self::canClaim($locked)) {
            throw new AuthorizationException;
        }

        return $locked;
    }

    public static function lockedApprover(User $actor): User
    {
        $locked = User::query()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();

        if (! self::canApprove($locked)) {
            throw new AuthorizationException;
        }

        return $locked;
    }

    public static function lockedPayrollOfficer(User $actor): User
    {
        $locked = User::query()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();

        if (! self::canRunPayroll($locked)) {
            throw new AuthorizationException;
        }

        return $locked;
    }
}
