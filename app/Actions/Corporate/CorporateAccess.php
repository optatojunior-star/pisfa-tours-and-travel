<?php

namespace App\Actions\Corporate;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Models\CorporateAccount;
use App\Models\CorporateMember;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Who may act for a company, and who may set its terms.
 *
 * Two different questions with two different answers: a customer's authority
 * comes from a membership row on the account, and PISFA staff authority comes
 * from their role. Credit limits and discounts sit above both, because they
 * commit the business to being owed money.
 */
final class CorporateAccess
{
    public static function canManage(?User $actor): bool
    {
        return $actor !== null
            && $actor->status === AccountStatus::Active
            && $actor->hasAnyRole(UserRole::Staff, UserRole::Manager, UserRole::SuperAdmin);
    }

    /** Agreeing terms: the credit limit, the discount, the payment days. */
    public static function canSetTerms(?User $actor): bool
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

    public static function lockedTermsSetter(User $actor): User
    {
        $locked = User::query()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();

        if (! self::canSetTerms($locked)) {
            throw new AuthorizationException;
        }

        return $locked;
    }

    /**
     * The membership that gives somebody authority on an account, if any.
     *
     * Always read fresh rather than cached on the user: a membership revoked a
     * minute ago must stop working now, not at the next login.
     */
    public static function membership(?User $actor, CorporateAccount $account): ?CorporateMember
    {
        if ($actor === null || $actor->status !== AccountStatus::Active) {
            return null;
        }

        return CorporateMember::query()
            ->where('corporate_account_id', $account->getKey())
            ->where('user_id', $actor->getKey())
            ->first();
    }

    /**
     * Whether somebody may raise a booking against a company.
     *
     * Both halves have to hold: the account must be trading, and the person
     * must have a live membership that books. A suspended account grants
     * nothing however senior the member.
     */
    public static function canBookFor(?User $actor, CorporateAccount $account): bool
    {
        if (! $account->status->canTrade()) {
            return false;
        }

        return self::membership($actor, $account)?->canBook() ?? false;
    }

    public static function canManageMembersOf(?User $actor, CorporateAccount $account): bool
    {
        if (self::canManage($actor)) {
            return true;
        }

        return self::membership($actor, $account)?->canManageMembers() ?? false;
    }

    /** Whether somebody may read the account at all. */
    public static function canView(?User $actor, CorporateAccount $account): bool
    {
        if (self::canManage($actor)) {
            return true;
        }

        // A deactivated member keeps no visibility: the row is kept for the
        // record, not as a way back in. `??` already covers the missing
        // membership, so the nullsafe operator would only be noise.
        return self::membership($actor, $account)->is_active ?? false;
    }
}
