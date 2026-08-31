<?php

namespace App\Policies;

use App\Models\AirportTransferLocation;
use App\Models\User;

class AirportTransferLocationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAccessAdministration();
    }

    public function view(User $user, AirportTransferLocation $location): bool
    {
        return $user->canAccessAdministration();
    }

    public function create(User $user): bool
    {
        return $user->canAccessAdministration();
    }

    public function update(User $user, AirportTransferLocation $location): bool
    {
        return $user->canAccessAdministration();
    }
}
