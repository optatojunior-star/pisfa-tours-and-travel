<?php

namespace App\Actions\Billing;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * The one place that decides who may price and issue money documents.
 *
 * Every billing action re-checks this against the row it locked, not against
 * the actor handed in by the request, so a role or suspension change that lands
 * mid-request is honoured rather than raced past.
 */
final class BillingAccess
{
    /** Quoting, issuing, cancelling, voiding. */
    public static function assertCanManage(User $actor): void
    {
        if (! self::canManage($actor)) {
            throw new AuthorizationException;
        }
    }

    public static function canManage(?User $actor): bool
    {
        return $actor !== null
            && $actor->status === AccountStatus::Active
            && $actor->hasAnyRole(UserRole::Staff, UserRole::Manager, UserRole::SuperAdmin);
    }

    /**
     * Voiding a paid invoice and writing off a receivable are corrections to
     * money already recognised, so they sit above ordinary staff.
     */
    public static function assertCanWriteOff(User $actor): void
    {
        if (! self::canWriteOff($actor)) {
            throw new AuthorizationException;
        }
    }

    public static function canWriteOff(?User $actor): bool
    {
        return $actor !== null
            && $actor->status === AccountStatus::Active
            && $actor->hasAnyRole(UserRole::Manager, UserRole::SuperAdmin);
    }
}
