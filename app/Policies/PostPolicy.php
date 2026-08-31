<?php

namespace App\Policies;

use App\Models\Post;
use App\Models\User;

/**
 * Editorial work belongs to operations staff.
 *
 * `view` is not the rule for public visibility: a published post is public to
 * everyone, and the public controller decides that by status and date. This
 * policy governs the console only.
 */
class PostPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAccessAdministration();
    }

    public function view(User $user, Post $post): bool
    {
        return $user->canAccessAdministration();
    }

    public function create(User $user): bool
    {
        return $user->canAccessAdministration();
    }

    /** An archived post is read-only until it is restored to a draft. */
    public function update(User $user, Post $post): bool
    {
        return $user->canAccessAdministration() && $post->status->isEditable();
    }

    /** Publishing, scheduling, unpublishing, and archiving. */
    public function publish(User $user, Post $post): bool
    {
        return $user->canAccessAdministration();
    }
}
