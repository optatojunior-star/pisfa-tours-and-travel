<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\User;

/**
 * The audit trail is the record of who did what. Reading it is a governance
 * power, not an operational one, so it belongs to the super administrator
 * alone — a manager appearing in the trail should not be the person who decides
 * what it says about them.
 *
 * There is deliberately no create, update, or delete ability. A trail somebody
 * can amend is not a trail.
 */
class AuditLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isActive() && $user->hasRole(UserRole::SuperAdmin);
    }

    public function view(User $user, AuditLog $auditLog): bool
    {
        return $this->viewAny($user);
    }
}
