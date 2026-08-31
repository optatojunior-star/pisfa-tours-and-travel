<?php

namespace App\Policies;

use App\Actions\Leasing\LeasingAccess;
use App\Models\User;
use App\Models\VehicleLease;

class VehicleLeasePolicy
{
    public function viewAny(User $user): bool
    {
        return LeasingAccess::canManage($user);
    }

    public function view(User $user, VehicleLease $lease): bool
    {
        return LeasingAccess::canManage($user)
            || (int) $lease->owner_id === (int) $user->getKey();
    }

    public function create(User $user): bool
    {
        return LeasingAccess::canCommit($user);
    }

    /** Terms are only editable while the agreement is a draft. */
    public function update(User $user, VehicleLease $lease): bool
    {
        return LeasingAccess::canCommit($user) && $lease->status->isEditable();
    }

    /**
     * Activating and ending the agreement, which moves the vehicle in and out
     * of the fleet.
     */
    public function commit(User $user, VehicleLease $lease): bool
    {
        return LeasingAccess::canCommit($user);
    }

    /** Taking a vehicle off hire is day-to-day operational work. */
    public function suspend(User $user, VehicleLease $lease): bool
    {
        return LeasingAccess::canManage($user);
    }
}
