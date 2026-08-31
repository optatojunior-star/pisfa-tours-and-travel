<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\TourBooking;
use App\Models\User;

class TourBookingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAccessAdministration();
    }

    public function view(User $user, TourBooking $tourBooking): bool
    {
        if (! $user->isActive()) {
            return false;
        }

        if ($user->canAccessAdministration()) {
            return true;
        }

        if ($user->hasRole(UserRole::Customer)) {
            return $tourBooking->customer_id === $user->id;
        }

        return $user->hasRole(UserRole::Driver)
            && $tourBooking->assigned_driver_user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->isActive() && $user->hasRole(UserRole::Customer);
    }

    public function cancel(User $user, TourBooking $tourBooking): bool
    {
        return $user->canAccessAdministration()
            || ($user->isActive()
                && $user->hasRole(UserRole::Customer)
                && $tourBooking->customer_id === $user->id);
    }

    public function transition(User $user, TourBooking $tourBooking): bool
    {
        return $user->canAccessAdministration();
    }

    public function assign(User $user, TourBooking $tourBooking): bool
    {
        return $user->canAccessAdministration();
    }
}
