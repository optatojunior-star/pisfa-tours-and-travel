<?php

namespace App\Policies;

use App\Actions\Sales\SalesAccess;
use App\Enums\ListingStatus;
use App\Models\User;
use App\Models\VehicleListing;

/**
 * The showroom console.
 *
 * `view` here is not what makes a listing public — the public showroom decides
 * that by status, through the `public` scope. This policy governs staff access
 * only.
 */
class VehicleListingPolicy
{
    public function viewAny(User $user): bool
    {
        return SalesAccess::canManage($user);
    }

    public function view(User $user, VehicleListing $listing): bool
    {
        return SalesAccess::canManage($user);
    }

    public function create(User $user): bool
    {
        return SalesAccess::canManage($user);
    }

    /** A sold or withdrawn listing is a record of what was advertised. */
    public function update(User $user, VehicleListing $listing): bool
    {
        return SalesAccess::canManage($user) && $listing->status->isEditable();
    }

    /** Listing, reserving, releasing, and withdrawing. */
    public function transition(User $user, VehicleListing $listing): bool
    {
        return SalesAccess::canManage($user);
    }

    /**
     * Recording the sale price, which every sales report is built from, and
     * which retires the vehicle from the hire fleet.
     */
    public function sell(User $user, VehicleListing $listing): bool
    {
        return SalesAccess::canCloseSale($user) && $listing->canTransitionTo(ListingStatus::Sold);
    }
}
