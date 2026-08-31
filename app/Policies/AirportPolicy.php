<?php

namespace App\Policies;

use App\Models\Airport;
use App\Models\User;

class AirportPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAccessAdministration();
    }

    public function view(User $user, Airport $airport): bool
    {
        return $user->canAccessAdministration();
    }

    public function create(User $user): bool
    {
        return $user->canAccessAdministration();
    }

    public function update(User $user, Airport $airport): bool
    {
        return $user->canAccessAdministration();
    }
}
