<?php

namespace App\Policies;

use App\Actions\Accommodation\AccommodationAccess;
use App\Models\Property;
use App\Models\User;

/**
 * The accommodation console.
 *
 * `view` here is not what makes a property public — the catalogue decides that
 * through the published scope, which needs a status *and* a date. This policy
 * governs staff access only.
 */
class PropertyPolicy
{
    public function viewAny(User $user): bool
    {
        return AccommodationAccess::canManage($user);
    }

    public function view(User $user, Property $property): bool
    {
        return AccommodationAccess::canManage($user);
    }

    public function create(User $user): bool
    {
        return AccommodationAccess::canManage($user);
    }

    /** An archived property is read-only until it is restored to a draft. */
    public function update(User $user, Property $property): bool
    {
        return AccommodationAccess::canManage($user) && $property->status->isEditable();
    }

    /**
     * Publishing, unpublishing, archiving, and pricing.
     *
     * Above day-to-day desk work: publishing is what makes a room bookable and
     * a price chargeable.
     */
    public function publish(User $user, Property $property): bool
    {
        return AccommodationAccess::canPublishOrPrice($user);
    }
}
