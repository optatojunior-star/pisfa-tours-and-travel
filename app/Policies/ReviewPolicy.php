<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Review;
use App\Models\User;

class ReviewPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAccessAdministration();
    }

    /**
     * A published review is public. Anything else is visible only to its author
     * and to moderators — a pending review must never leak.
     */
    public function view(User $user, Review $review): bool
    {
        if ($review->isPublic()) {
            return true;
        }

        return $user->canAccessAdministration() || $this->owns($user, $review);
    }

    public function create(User $user): bool
    {
        return $user->isActive() && $user->hasRole(UserRole::Customer);
    }

    /**
     * The author may revise their own review while it is still pending,
     * published, or rejected. An edit sends it back to moderation.
     */
    public function update(User $user, Review $review): bool
    {
        return $this->owns($user, $review) && $review->status->isCustomerEditable();
    }

    /** An author may withdraw their own review; staff may remove any. */
    public function delete(User $user, Review $review): bool
    {
        return $this->owns($user, $review) || $user->canAccessAdministration();
    }

    public function moderate(User $user, Review $review): bool
    {
        return $user->canAccessAdministration();
    }

    public function reply(User $user, Review $review): bool
    {
        return $user->canAccessAdministration();
    }

    private function owns(User $user, Review $review): bool
    {
        return $user->isActive()
            && $user->hasRole(UserRole::Customer)
            && $review->customer_id === $user->getKey();
    }
}
