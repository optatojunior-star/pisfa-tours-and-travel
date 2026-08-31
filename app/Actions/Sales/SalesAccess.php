<?php

namespace App\Actions\Sales;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Who may run the showroom.
 *
 * Re-checked against the locked user row inside every action, so a role change
 * or suspension landing mid-request is honoured rather than raced past.
 */
final class SalesAccess
{
    public static function canManage(?User $actor): bool
    {
        return $actor !== null
            && $actor->status === AccountStatus::Active
            && $actor->hasAnyRole(UserRole::Staff, UserRole::Manager, UserRole::SuperAdmin);
    }

    /**
     * Recording a sale sets the figure every sales report is built from, so it
     * sits above day-to-day listing work.
     */
    public static function canCloseSale(?User $actor): bool
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

    public static function lockedCloser(User $actor): User
    {
        $locked = User::query()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();

        if (! self::canCloseSale($locked)) {
            throw new AuthorizationException;
        }

        return $locked;
    }
}
