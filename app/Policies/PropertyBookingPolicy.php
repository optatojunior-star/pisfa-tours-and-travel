<?php

namespace App\Policies;

use App\Actions\Accommodation\AccommodationAccess;
use App\Enums\PropertyBookingStatus;
use App\Models\PropertyBooking;
use App\Models\User;

class PropertyBookingPolicy
{
    public function viewAny(User $user): bool
    {
        return AccommodationAccess::canManage($user);
    }

    public function view(User $user, PropertyBooking $booking): bool
    {
        return AccommodationAccess::canManage($user)
            || (int) $booking->customer_id === (int) $user->getKey();
    }

    /** Confirming, declining, checking in and out. */
    public function manage(User $user, PropertyBooking $booking): bool
    {
        return AccommodationAccess::canManage($user);
    }

    /**
     * A guest cancelling their own stay.
     *
     * The free-cancellation cutoff is enforced in the action rather than here:
     * a policy that returned false past the cutoff would render the button
     * missing with no explanation, when what the guest needs is the reason.
     */
    public function cancelAsCustomer(User $user, PropertyBooking $booking): bool
    {
        return (int) $booking->customer_id === (int) $user->getKey()
            && $booking->canTransitionTo(PropertyBookingStatus::Cancelled);
    }
}
