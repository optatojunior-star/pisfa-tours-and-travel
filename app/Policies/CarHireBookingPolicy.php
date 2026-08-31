<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\CarHireBooking;
use App\Models\User;

class CarHireBookingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAccessAdministration();
    }

    public function view(User $user, CarHireBooking $booking): bool
    {
        return $user->canAccessAdministration() || $this->owns($user, $booking);
    }

    public function create(User $user): bool
    {
        return $user->isActive()
            && $user->hasRole(UserRole::Customer)
            && $user->email_verified_at !== null;
    }

    public function cancel(User $user, CarHireBooking $booking): bool
    {
        return $user->canAccessAdministration() || $this->owns($user, $booking);
    }

    public function updateSelfDriveApplication(User $user, CarHireBooking $booking): bool
    {
        return $this->owns($user, $booking);
    }

    public function uploadDocument(User $user, CarHireBooking $booking): bool
    {
        return $this->owns($user, $booking);
    }

    public function downloadDocument(User $user, CarHireBooking $booking): bool
    {
        return $user->canAccessAdministration() || $this->owns($user, $booking);
    }

    public function acceptContract(User $user, CarHireBooking $booking): bool
    {
        return $this->owns($user, $booking);
    }

    public function downloadContract(User $user, CarHireBooking $booking): bool
    {
        return $user->canAccessAdministration() || $this->owns($user, $booking);
    }

    public function update(User $user, CarHireBooking $booking): bool
    {
        return $user->canAccessAdministration();
    }

    public function manage(User $user, CarHireBooking $booking): bool
    {
        return $user->canAccessAdministration();
    }

    public function transition(User $user, CarHireBooking $booking): bool
    {
        return $user->canAccessAdministration();
    }

    public function assign(User $user, CarHireBooking $booking): bool
    {
        return $user->canAccessAdministration();
    }

    public function assignDriver(User $user, CarHireBooking $booking): bool
    {
        return $this->assign($user, $booking);
    }

    public function reviewSelfDriveApplication(User $user, CarHireBooking $booking): bool
    {
        return $user->canAccessAdministration();
    }

    public function verifySelfDriveOriginals(User $user, CarHireBooking $booking): bool
    {
        return $user->canAccessAdministration();
    }

    private function owns(User $user, CarHireBooking $booking): bool
    {
        return $user->isActive()
            && $user->hasRole(UserRole::Customer)
            && $booking->customer_id === $user->getKey();
    }
}
