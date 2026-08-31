<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\FlightInquiry;
use App\Models\User;

class FlightInquiryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAccessAdministration();
    }

    public function view(User $user, FlightInquiry $inquiry): bool
    {
        return $user->canAccessAdministration() || $this->owns($user, $inquiry);
    }

    public function create(User $user): bool
    {
        return $user->isActive() && $user->hasRole(UserRole::Customer);
    }

    public function transition(User $user, FlightInquiry $inquiry): bool
    {
        return $user->canAccessAdministration();
    }

    public function assign(User $user, FlightInquiry $inquiry): bool
    {
        return $user->canAccessAdministration();
    }

    public function comment(User $user, FlightInquiry $inquiry): bool
    {
        return $user->canAccessAdministration();
    }

    /**
     * Reopening a resolved inquiry is restricted to the finance-and-operations
     * leadership roles named in config, not to every staff account.
     */
    public function reopen(User $user, FlightInquiry $inquiry): bool
    {
        if (! $user->canAccessAdministration()) {
            return false;
        }

        $allowed = (array) config('flight_inquiries.reopen.roles', ['manager', 'super_admin']);

        return in_array($user->role->value, $allowed, true);
    }

    private function owns(User $user, FlightInquiry $inquiry): bool
    {
        return $user->isActive()
            && $user->hasRole(UserRole::Customer)
            && $inquiry->customer_id === $user->getKey();
    }
}
