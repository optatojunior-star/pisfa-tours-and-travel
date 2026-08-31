<?php

namespace App\Policies;

use App\Actions\Leasing\LeasingAccess;
use App\Models\User;
use App\Models\VehicleLeaseApplication;

/**
 * Leasing offers.
 *
 * An owner who applied while signed in may read their own offer back; a guest
 * offer has no owner to authorise, which is why guests get a reference by email
 * and a lookup by reference instead of an account page.
 */
class VehicleLeaseApplicationPolicy
{
    public function viewAny(User $user): bool
    {
        return LeasingAccess::canManage($user);
    }

    public function view(User $user, VehicleLeaseApplication $application): bool
    {
        if (LeasingAccess::canManage($user)) {
            return true;
        }

        return $application->owner_id !== null
            && (int) $application->owner_id === (int) $user->getKey();
    }

    /** Reviewing, arranging an inspection, recording findings, assigning. */
    public function manage(User $user, VehicleLeaseApplication $application): bool
    {
        return LeasingAccess::canManage($user);
    }

    /** Approving an offer commits PISFA to running somebody else's vehicle. */
    public function approve(User $user, VehicleLeaseApplication $application): bool
    {
        return LeasingAccess::canCommit($user);
    }
}
