<?php

namespace App\Policies;

use App\Actions\Messaging\MessagingAccess;
use App\Models\Conversation;
use App\Models\User;

/**
 * The staff inbox.
 *
 * A guest's access to their own chat is not decided here — they have no account
 * to authorise. That entitlement lives in the chat controller, which resolves a
 * thread from the reference their own session opened.
 */
class ConversationPolicy
{
    public function viewAny(User $user): bool
    {
        return MessagingAccess::canHandle($user);
    }

    public function view(User $user, Conversation $conversation): bool
    {
        if (MessagingAccess::canHandle($user)) {
            return true;
        }

        // A signed-in customer may read their own thread back.
        return $conversation->customer_id !== null
            && (int) $conversation->customer_id === (int) $user->getKey();
    }

    /** Replying, noting, assigning, resolving, and closing. */
    public function reply(User $user, Conversation $conversation): bool
    {
        return MessagingAccess::canHandle($user);
    }
}
