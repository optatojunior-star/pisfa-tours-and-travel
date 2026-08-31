<?php

namespace App\Policies;

use App\Actions\Corporate\CorporateAccess;
use App\Models\GroupBooking;
use App\Models\User;

class GroupBookingPolicy
{
    public function viewAny(User $user): bool
    {
        return CorporateAccess::canManage($user);
    }

    /**
     * The organiser, anybody live on the company account, or PISFA staff.
     *
     * A colleague on the account can see the group even if they did not raise
     * it: somebody has to be able to check the traveller list when the person
     * who booked it is on leave.
     */
    public function view(User $user, GroupBooking $booking): bool
    {
        if (CorporateAccess::canManage($user)) {
            return true;
        }

        if ((int) $booking->organiser_id === (int) $user->getKey()) {
            return true;
        }

        $booking->loadMissing('account');

        return $booking->account !== null
            && CorporateAccess::canView($user, $booking->account);
    }

    public function create(User $user): bool
    {
        return $user->exists;
    }

    /** Changing the trip itself — dates, headcount, requirements. */
    public function update(User $user, GroupBooking $booking): bool
    {
        if (! $booking->status->isOpen()) {
            return false;
        }

        if (CorporateAccess::canManage($user) || (int) $booking->organiser_id === (int) $user->getKey()) {
            return true;
        }

        $booking->loadMissing('account');

        return $booking->account !== null
            && (CorporateAccess::membership($user, $booking->account)?->canApprove() ?? false);
    }

    /** Adding and removing names on the manifest. */
    public function manageManifest(User $user, GroupBooking $booking): bool
    {
        if (! $booking->status->manifestIsEditable()) {
            return false;
        }

        if (CorporateAccess::canManage($user) || (int) $booking->organiser_id === (int) $user->getKey()) {
            return true;
        }

        $booking->loadMissing('account');

        return $booking->account !== null
            && (CorporateAccess::membership($user, $booking->account)?->canBook() ?? false);
    }

    /** Quoting, confirming, and closing — PISFA's side of the arrangement. */
    public function transition(User $user, GroupBooking $booking): bool
    {
        return CorporateAccess::canManage($user);
    }
}
