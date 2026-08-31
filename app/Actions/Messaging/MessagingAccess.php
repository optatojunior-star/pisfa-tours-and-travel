<?php

namespace App\Actions\Messaging;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Who may work the inbox.
 *
 * Re-checked against the locked user row inside every action, so a suspension
 * or role change landing mid-request is honoured rather than raced past.
 */
final class MessagingAccess
{
    public static function canHandle(?User $actor): bool
    {
        return $actor !== null
            && $actor->status === AccountStatus::Active
            && $actor->hasAnyRole(UserRole::Staff, UserRole::Manager, UserRole::SuperAdmin);
    }

    public static function lockedHandler(User $actor): User
    {
        $locked = User::query()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();

        if (! self::canHandle($locked)) {
            throw new AuthorizationException;
        }

        return $locked;
    }
}
