<?php

namespace App\Actions\Fleet;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Who may record fleet work.
 *
 * Re-checked against the locked user row inside every action rather than
 * trusting the actor handed in by the request, so a role change or suspension
 * landing mid-request is honoured instead of raced past.
 */
final class FleetAccess
{
    public static function canManage(?User $actor): bool
    {
        return $actor !== null
            && $actor->status === AccountStatus::Active
            && $actor->hasAnyRole(UserRole::Staff, UserRole::Manager, UserRole::SuperAdmin);
    }

    /**
     * Retiring a vehicle or writing off maintenance history is a decision above
     * day-to-day recording.
     */
    public static function canRetire(?User $actor): bool
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
}
