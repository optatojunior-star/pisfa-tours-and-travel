<?php

namespace App\Policies;

use App\Actions\Leasing\LeasingAccess;
use App\Models\User;
use App\Models\VehicleLeasePayout;

class VehicleLeasePayoutPolicy
{
    public function viewAny(User $user): bool
    {
        return LeasingAccess::canManage($user);
    }

    /** A draft is internal working and never reaches the owner. */
    public function view(User $user, VehicleLeasePayout $payout): bool
    {
        if (LeasingAccess::canManage($user)) {
            return true;
        }

        $payout->loadMissing('lease');

        return $payout->status->isVisibleToOwner()
            && (int) ($payout->lease->owner_id ?? 0) === (int) $user->getKey();
    }

    /** Recalculating and recording deductions, before anything is promised. */
    public function draft(User $user, VehicleLeasePayout $payout): bool
    {
        return LeasingAccess::canManage($user) && $payout->status->isEditable();
    }

    /** Approving, paying, and cancelling money. */
    public function settle(User $user, VehicleLeasePayout $payout): bool
    {
        return LeasingAccess::canCommit($user);
    }
}
