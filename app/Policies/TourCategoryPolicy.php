<?php

namespace App\Policies;

use App\Models\TourCategory;
use App\Models\User;

class TourCategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAccessAdministration();
    }

    public function create(User $user): bool
    {
        return $user->canAccessAdministration();
    }

    public function update(User $user, TourCategory $tourCategory): bool
    {
        return $user->canAccessAdministration();
    }
}
