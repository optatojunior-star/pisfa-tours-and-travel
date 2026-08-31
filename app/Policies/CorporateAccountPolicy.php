<?php

namespace App\Policies;

use App\Actions\Corporate\CorporateAccess;
use App\Models\CorporateAccount;
use App\Models\User;

/**
 * Company accounts.
 *
 * Two audiences with different rights: PISFA staff run the account, and the
 * company's own people read it and manage their colleagues.
 */
class CorporateAccountPolicy
{
    public function viewAny(User $user): bool
    {
        return CorporateAccess::canManage($user);
    }

    public function view(User $user, CorporateAccount $account): bool
    {
        return CorporateAccess::canView($user, $account);
    }

    public function create(User $user): bool
    {
        return CorporateAccess::canSetTerms($user);
    }

    /** Contact and billing details. A closed account is read-only. */
    public function update(User $user, CorporateAccount $account): bool
    {
        return CorporateAccess::canManage($user) && $account->status->isEditable();
    }

    /** Credit limit, discount, payment days — what the business is owed. */
    public function setTerms(User $user, CorporateAccount $account): bool
    {
        return CorporateAccess::canSetTerms($user) && $account->status->isEditable();
    }

    /** Activating, suspending, closing. */
    public function transition(User $user, CorporateAccount $account): bool
    {
        return CorporateAccess::canSetTerms($user);
    }

    /** Adding and removing colleagues — staff, or the account's administrator. */
    public function manageMembers(User $user, CorporateAccount $account): bool
    {
        return CorporateAccess::canManageMembersOf($user, $account);
    }

    /** Raising a booking against the company. */
    public function book(User $user, CorporateAccount $account): bool
    {
        return CorporateAccess::canBookFor($user, $account)
            || (CorporateAccess::canManage($user) && $account->status->canTrade());
    }
}
