<?php

namespace App\Actions\Drivers;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;

/**
 * Who may act on a driver assignment.
 *
 * Ownership is re-proved against the locked assignment row inside every action,
 * never inferred from the route: a driver must only ever be able to start, check,
 * or close their own work.
 */
final class DriverAccess
{
    public static function isDriver(?User $actor): bool
    {
        return $actor !== null
            && $actor->status === AccountStatus::Active
            && $actor->hasRole(UserRole::Driver);
    }

    public static function lockedDriver(User $actor): User
    {
        $locked = User::query()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();

        if (! self::isDriver($locked)) {
            throw new AuthorizationException;
        }

        return $locked;
    }

    /**
     * Confirms the assignment belongs to this driver.
     *
     * A withdrawn assignment fails too: once the office has taken the job away,
     * it is no longer the driver's to act on, even if they still hold the link.
     */
    public static function assertOwns(User $driver, Model $assignment): void
    {
        $ownerId = $assignment->getAttribute('driver_user_id');
        $withdrawn = $assignment->getAttribute('unassigned_at');

        if ((int) $ownerId !== (int) $driver->getKey() || $withdrawn !== null) {
            throw new AuthorizationException;
        }
    }
}
