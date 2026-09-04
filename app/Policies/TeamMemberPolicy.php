<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\TeamMember;
use App\Models\User;

/**
 * Who the company says it is, is an operations decision.
 *
 * `view` is not the rule for public visibility — a published profile is public
 * to everyone, and the about page decides that by its published flag. This
 * governs the console only.
 */
class TeamMemberPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAccessAdministration();
    }

    public function view(User $user, TeamMember $member): bool
    {
        return $user->canAccessAdministration();
    }

    public function create(User $user): bool
    {
        return $user->canAccessAdministration();
    }

    public function update(User $user, TeamMember $member): bool
    {
        return $user->canAccessAdministration();
    }

    /** Deletion removes a person's photograph from the site, so it is narrower. */
    public function delete(User $user, TeamMember $member): bool
    {
        return $user->isActive() && in_array($user->role, [UserRole::Manager, UserRole::SuperAdmin], true);
    }
}
