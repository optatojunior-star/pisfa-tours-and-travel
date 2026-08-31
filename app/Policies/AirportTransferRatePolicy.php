<?php

namespace App\Policies;

use App\Models\AirportTransferRate;
use App\Models\User;

class AirportTransferRatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAccessAdministration();
    }

    public function view(User $user, AirportTransferRate $rate): bool
    {
        return $user->canAccessAdministration();
    }

    public function create(User $user): bool
    {
        return $user->canAccessAdministration();
    }

    public function update(User $user, AirportTransferRate $rate): bool
    {
        return $user->canAccessAdministration();
    }
}
