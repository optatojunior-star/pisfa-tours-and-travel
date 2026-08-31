<?php

namespace App\Policies;

use App\Actions\Sales\SalesAccess;
use App\Models\User;
use App\Models\VehicleSalesEnquiry;

/**
 * Sales leads.
 *
 * A customer who enquired while signed in may read their own enquiry back in
 * the portal; a guest enquiry has no owner to authorise, which is why guests get
 * a reference by email instead of an account page.
 */
class VehicleSalesEnquiryPolicy
{
    public function viewAny(User $user): bool
    {
        return SalesAccess::canManage($user);
    }

    public function view(User $user, VehicleSalesEnquiry $enquiry): bool
    {
        if (SalesAccess::canManage($user)) {
            return true;
        }

        return $enquiry->customer_id !== null
            && (int) $enquiry->customer_id === (int) $user->getKey();
    }

    /** Advancing the pipeline, assigning it, and adding internal notes. */
    public function manage(User $user, VehicleSalesEnquiry $enquiry): bool
    {
        return SalesAccess::canManage($user);
    }
}
