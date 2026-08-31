<?php

namespace App\Actions\Leasing;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Who may run the leasing desk.
 *
 * Re-checked against the locked user row inside every action, so a role change
 * or suspension landing mid-request is honoured rather than raced past.
 */
final class LeasingAccess
{
    public static function canManage(?User $actor): bool
    {
        return $actor !== null
            && $actor->status === AccountStatus::Active
            && $actor->hasAnyRole(UserRole::Staff, UserRole::Manager, UserRole::SuperAdmin);
    }

    /**
     * Agreeing terms, activating and ending a lease, and approving money.
     *
     * Above day-to-day desk work: these are what commit PISFA to paying
     * somebody, and to running a vehicle it does not own.
     */
    public static function canCommit(?User $actor): bool
    {
        return $actor !== null
            && $actor->status === AccountStatus::Active
            && $actor->hasAnyRole(UserRole::Manager, UserRole::SuperAdmin);
    }

    public static function lockedManager(User $actor): User
    {
        $locked = User::query()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();

        if (! self::canManage($locked)) {
            throw new AuthorizationException;
        }

        return $locked;
    }

    public static function lockedCommitter(User $actor): User
    {
        $locked = User::query()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();

        if (! self::canCommit($locked)) {
            throw new AuthorizationException;
        }

        return $locked;
    }
}
