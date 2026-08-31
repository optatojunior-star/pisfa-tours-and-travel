<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\AirportTransferBooking;
use App\Models\User;

class AirportTransferBookingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAccessAdministration();
    }

    public function view(User $user, AirportTransferBooking $booking): bool
    {
        return $user->canAccessAdministration() || $this->owns($user, $booking);
    }

    public function create(User $user): bool
    {
        return $user->isActive()
            && $user->hasRole(UserRole::Customer)
            && $user->email_verified_at !== null;
    }

    public function cancel(User $user, AirportTransferBooking $booking): bool
    {
        return $user->canAccessAdministration() || $this->owns($user, $booking);
    }

    public function update(User $user, AirportTransferBooking $booking): bool
    {
        return $user->canAccessAdministration();
    }

    public function transition(User $user, AirportTransferBooking $booking): bool
    {
        return $user->canAccessAdministration();
    }

    public function assign(User $user, AirportTransferBooking $booking): bool
    {
        return $user->canAccessAdministration();
    }

    private function owns(User $user, AirportTransferBooking $booking): bool
    {
        return $user->isActive()
            && $user->hasRole(UserRole::Customer)
            && $booking->customer_id === $user->getKey();
    }
}
