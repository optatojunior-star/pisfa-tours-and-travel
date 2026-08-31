<?php

namespace App\Services;

use App\Enums\StaffRoles;
use App\Enums\UserRole;
use App\Models\User;

class StaffUserPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isActiveSuperAdministrator($user);
    }

    public function create(User $user): bool
    {
        return $this->isActiveSuperAdministrator($user);
    }

    public function update(User $user, User $staff): bool
    {
        return $this->isActiveSuperAdministrator($user)
            && StaffRoles::isManageable($staff->role);
    }

    public function resendInvitation(User $user, User $staff): bool
    {
        return $this->update($user, $staff);
    }

    /**
     * The operations console.
     *
     * Super administrators only. The health report names which subsystem is
     * failing and the failed-job list carries exception messages — neither
     * belongs in front of ordinary staff.
     */
    public function viewOperations(User $user): bool
    {
        return $this->isActiveSuperAdministrator($user);
    }

    private function isActiveSuperAdministrator(User $user): bool
    {
        return $user->hasRole(UserRole::SuperAdmin) && $user->isActive();
    }
}
