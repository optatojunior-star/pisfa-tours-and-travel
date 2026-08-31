<?php

namespace App\Actions\Messaging;

use App\Enums\ConversationStatus;
use App\Models\Conversation;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Moves a thread through the inbox and puts a name on it.
 */
class TransitionConversation
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function resolve(User $actor, Conversation $conversation): Conversation
    {
        return $this->moveTo($actor, $conversation, ConversationStatus::Resolved, 'conversation.resolved', [
            'resolved_at' => now(),
        ]);
    }

    public function reopen(User $actor, Conversation $conversation): Conversation
    {
        return $this->moveTo($actor, $conversation, ConversationStatus::Open, 'conversation.reopened', [
            'resolved_at' => null,
        ]);
    }

    /**
     * Closing is the deliberate end of a conversation.
     *
     * Unlike resolving, a customer writing again does not undo it — their reply
     * starts a new thread — so it asks for a reason.
     */
    public function close(User $actor, Conversation $conversation, string $reason): Conversation
    {
        $reason = trim($reason);

        Validator::make(
            ['reason' => $reason],
            ['reason' => ['required', 'string', 'min:5', 'max:255']],
        )->validate();

        return $this->moveTo($actor, $conversation, ConversationStatus::Closed, 'conversation.closed', [
            'closed_at' => now(),
            'closure_reason' => $reason,
        ]);
    }

    public function assign(User $actor, Conversation $conversation, ?User $assignee): Conversation
    {
        return DB::transaction(function () use ($actor, $conversation, $assignee): Conversation {
            $lockedActor = MessagingAccess::lockedHandler($actor);

            $lockedAssignee = null;

            if ($assignee !== null) {
                $lockedAssignee = User::query()
                    ->whereKey($assignee->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                // Assigning a thread to somebody who cannot open the inbox
                // would hide it rather than delegate it.
                if (! MessagingAccess::canHandle($lockedAssignee)) {
                    throw ValidationException::withMessages([
                        'assigned_to_user_id' => 'That person does not have access to the inbox.',
                    ]);
                }
            }

            $locked = Conversation::query()
                ->whereKey($conversation->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $previous = $locked->assigned_to_user_id;

            $locked->forceFill(['assigned_to_user_id' => $lockedAssignee?->getKey()])->save();

            $this->auditLogger->record(
                event: 'conversation.assigned',
                auditable: $locked,
                oldValues: ['assigned_to_user_id' => $previous],
                newValues: ['assigned_to_user_id' => $lockedAssignee?->getKey()],
                user: $lockedActor,
            );

            return $locked->fresh(['assignee', 'customer']) ?? $locked;
        }, 3);
    }

    /** @param array<string, mixed> $extra */
    private function moveTo(
        User $actor,
        Conversation $conversation,
        ConversationStatus $next,
        string $event,
        array $extra = [],
    ): Conversation {
        return DB::transaction(function () use ($actor, $conversation, $next, $event, $extra): Conversation {
            $lockedActor = MessagingAccess::lockedHandler($actor);

            $locked = Conversation::query()
                ->whereKey($conversation->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === $next) {
                return $locked;
            }

            if (! $locked->canTransitionTo($next)) {
                throw ValidationException::withMessages([
                    'status' => "A conversation that is {$locked->status->label()} cannot become {$next->label()}.",
                ]);
            }

            $previous = $locked->status;

            $locked->forceFill(array_merge($extra, ['status' => $next]))->save();

            $this->auditLogger->record(
                event: $event,
                auditable: $locked,
                oldValues: ['status' => $previous->value],
                newValues: ['status' => $next->value],
                user: $lockedActor,
            );

            return $locked->fresh(['assignee', 'customer']) ?? $locked;
        }, 3);
    }
}
