<?php

namespace App\Policies;

use App\Models\TourDeparture;
use App\Models\TourPackage;
use App\Models\User;

class TourDeparturePolicy
{
    public function create(User $user, TourPackage $tourPackage): bool
    {
        return $user->canAccessAdministration();
    }

    public function update(User $user, TourDeparture $tourDeparture): bool
    {
        return $user->canAccessAdministration();
    }

    public function changeStatus(User $user, TourDeparture $tourDeparture): bool
    {
        return $user->canAccessAdministration();
    }
}
