<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

/**
 * Configuration is a super-administrator action.
 *
 * A manager can move money; changing what every document says about the company,
 * or whether the site accepts bookings at all, is a different kind of authority.
 */
class SettingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isActive() && $user->hasRole(UserRole::SuperAdmin);
    }

    public function update(User $user): bool
    {
        return $this->viewAny($user);
    }
}
