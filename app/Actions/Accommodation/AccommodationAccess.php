<?php

namespace App\Actions\Accommodation;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Who may run the accommodation desk.
 *
 * Re-checked against the locked user row inside every action, so a role change
 * or a suspension landing mid-request is honoured rather than raced past.
 */
final class AccommodationAccess
{
    public static function canManage(?User $actor): bool
    {
        return $actor !== null
            && $actor->status === AccountStatus::Active
            && $actor->hasAnyRole(UserRole::Staff, UserRole::Manager, UserRole::SuperAdmin);
    }

    /**
     * Publishing a property, and changing what a room costs.
     *
     * Above day-to-day desk work: a wrong nightly rate is charged to every
     * guest who books before somebody notices.
     */
    public static function canPublishOrPrice(?User $actor): bool
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

    public static function lockedPublisher(User $actor): User
    {
        $locked = User::query()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();

        if (! self::canPublishOrPrice($locked)) {
            throw new AuthorizationException;
        }

        return $locked;
    }
}
