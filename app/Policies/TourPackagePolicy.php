<?php

namespace App\Policies;

use App\Models\TourPackage;
use App\Models\User;

class TourPackagePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAccessAdministration();
    }

    public function view(User $user, TourPackage $tourPackage): bool
    {
        return $user->canAccessAdministration();
    }

    public function create(User $user): bool
    {
        return $user->canAccessAdministration();
    }

    public function update(User $user, TourPackage $tourPackage): bool
    {
        return $user->canAccessAdministration();
    }

    public function publish(User $user, TourPackage $tourPackage): bool
    {
        return $user->canAccessAdministration();
    }

    public function archive(User $user, TourPackage $tourPackage): bool
    {
        return $user->canAccessAdministration();
    }
}
