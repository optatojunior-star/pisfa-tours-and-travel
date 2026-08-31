<?php

namespace App\Policies;

use App\Enums\DocumentVisibility;
use App\Models\Document;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * A document has no authority of its own. It inherits the authority of the
 * record it belongs to: if you may view the booking, you may view its
 * contract. That keeps one ownership rule per domain instead of a second,
 * drifting copy here.
 */
class DocumentPolicy
{
    public function view(User $user, Document $document): bool
    {
        if (! $user->isActive()) {
            return false;
        }

        if ($document->visibility === DocumentVisibility::Public) {
            return true;
        }

        return $this->canReachOwner($user, $document, 'view');
    }

    public function download(User $user, Document $document): bool
    {
        return $this->view($user, $document);
    }

    public function delete(User $user, Document $document): bool
    {
        if (! $user->isActive()) {
            return false;
        }

        // A generated contract or receipt is financial evidence. Removing one
        // is an operations decision, never a customer self-service action.
        if ($document->is_generated) {
            return $user->canAccessAdministration();
        }

        return $this->canReachOwner($user, $document, 'update')
            || $user->canAccessAdministration();
    }

    private function canReachOwner(User $user, Document $document, string $ability): bool
    {
        $owner = $document->documentable;

        if (! $owner instanceof Model) {
            // An orphaned document must not become universally readable.
            return $user->canAccessAdministration();
        }

        // Defer to the owning model's own policy when one is registered.
        if (Gate::getPolicyFor($owner) !== null) {
            return Gate::forUser($user)->allows($ability, $owner);
        }

        return $user->canAccessAdministration();
    }
}
