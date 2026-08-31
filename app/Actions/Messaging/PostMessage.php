<?php

namespace App\Actions\Messaging;

use App\Contracts\Messaging\TransportResult;
use App\Enums\ConversationChannel;
use App\Enums\ConversationStatus;
use App\Enums\MessageAuthorType;
use App\Enums\MessageDeliveryStatus;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\User;
use App\Notifications\Messaging\CustomerMessageReceivedNotification;
use App\Services\AuditLogger;
use App\Services\Messaging\AutoReplyResolver;
use App\Services\Messaging\MessagingTransportRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Adds a message to a thread.
 *
 * Every message goes through here — the customer's, staff replies, the bot's,
 * and inbound WhatsApp — so that the thread's own bookkeeping (who spoke last,
 * whose court it is in, whether the bot may answer) is written in exactly one
 * place. Three near-identical copies of that logic is how a conversation ends
 * up saying it is waiting on a customer who has already replied twice.
 */
class PostMessage
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly AutoReplyResolver $autoReply,
        private readonly MessagingTransportRegistry $transports,
    ) {}

    /**
     * A message from the person PISFA is talking to.
     *
     * Also the path for inbound WhatsApp, which is the same event arriving by a
     * different road.
     */
    public function fromCustomer(
        Conversation $conversation,
        string $body,
        ?string $idempotencyKey = null,
        ?ConversationChannel $channel = null,
        ?string $providerMessageId = null,
    ): ConversationMessage {
        $body = $this->validatedBody($body);

        $message = DB::transaction(function () use (
            $conversation,
            $body,
            $idempotencyKey,
            $channel,
            $providerMessageId,
        ): ConversationMessage {
            $locked = $this->lockedConversation($conversation);

            $replayed = $this->replayed($locked, $idempotencyKey);

            if ($replayed !== null) {
                return $replayed;
            }

            $message = $this->write($locked, [
                'author_type' => MessageAuthorType::Customer,
                'author_user_id' => $locked->customer_id,
                'channel' => $channel ?? $locked->channel,
                'body' => $body,
                'is_internal_note' => false,
                // Inbound messages have already arrived by definition; there is
                // nothing to deliver and nothing to wait for.
                'delivery_status' => MessageDeliveryStatus::Delivered,
                'delivered_at' => now(),
                'provider_message_id' => $providerMessageId,
                'idempotency_key' => $idempotencyKey,
            ]);

            // A resolved thread reopens when the customer writes again: they
            // clearly did not consider it finished. A closed one does not —
            // closing is deliberate, and a reply to it starts a new thread.
            $status = $locked->status === ConversationStatus::Closed
                ? ConversationStatus::Closed
                : ConversationStatus::Open;

            $locked->forceFill([
                'status' => $status,
                'last_message_at' => $message->created_at,
                'last_customer_message_at' => $message->created_at,
                'resolved_at' => $status === ConversationStatus::Open ? null : $locked->resolved_at,
            ])->save();

            return $message;
        }, 3);

        // Outside the transaction: a slow provider or mail server must not hold
        // a write lock on the conversation, and neither must fail the message
        // that has already been accepted.
        $this->afterCustomerMessage($conversation->fresh() ?? $conversation, $message);

        return $message;
    }

    /** A reply, or an internal note, written by a member of staff. */
    public function fromStaff(
        User $actor,
        Conversation $conversation,
        string $body,
        bool $internalNote = false,
    ): ConversationMessage {
        $body = $this->validatedBody($body);

        [$message, $shouldSend] = DB::transaction(function () use (
            $actor,
            $conversation,
            $body,
            $internalNote,
        ): array {
            $lockedActor = MessagingAccess::lockedHandler($actor);
            $locked = $this->lockedConversation($conversation);

            // An internal note is staff talking to each other about the thread,
            // so it neither leaves the building nor moves the conversation on.
            if ($internalNote) {
                $message = $this->write($locked, [
                    'author_type' => MessageAuthorType::System,
                    'author_user_id' => $lockedActor->getKey(),
                    'channel' => $locked->channel,
                    'body' => $body,
                    'is_internal_note' => true,
                    'delivery_status' => MessageDeliveryStatus::Delivered,
                    'delivered_at' => now(),
                ]);

                $this->auditLogger->record(
                    event: 'conversation.note_added',
                    auditable: $locked,
                    // The note body is not audited: it is free text that may
                    // quote the customer, and the audit trail is read by more
                    // people than the conversation is.
                    newValues: ['characters' => mb_strlen($body)],
                    user: $lockedActor,
                );

                return [$message, false];
            }

            $sendsThroughProvider = $locked->channel->usesProvider();

            if ($sendsThroughProvider && ! $locked->isReachable()) {
                throw ValidationException::withMessages([
                    'body' => 'This WhatsApp conversation has no phone number to reply to.',
                ]);
            }

            $message = $this->write($locked, [
                'author_type' => MessageAuthorType::Staff,
                'author_user_id' => $lockedActor->getKey(),
                'channel' => $locked->channel,
                'body' => $body,
                'is_internal_note' => false,
                // Website chat is delivered by the customer's page polling, so
                // it is delivered the moment it is written. WhatsApp has to go
                // out through a provider first.
                'delivery_status' => $sendsThroughProvider
                    ? MessageDeliveryStatus::Pending
                    : MessageDeliveryStatus::Delivered,
                'delivered_at' => $sendsThroughProvider ? null : now(),
            ]);

            $locked->forceFill([
                'status' => ConversationStatus::AwaitingCustomer,
                'last_message_at' => $message->created_at,
                'last_staff_message_at' => $message->created_at,
                // Whoever answers a thread nobody had picked up owns it.
                'assigned_to_user_id' => $locked->assigned_to_user_id ?? $lockedActor->getKey(),
            ])->save();

            return [$message, $sendsThroughProvider];
        }, 3);

        if ($shouldSend) {
            $this->deliver($conversation->fresh() ?? $conversation, $message);
        }

        return $message;
    }

    /**
     * The automatic reply.
     *
     * Only ever called by `afterCustomerMessage`, and only when the resolver
     * has cleared all three of its guards.
     */
    private function fromBot(Conversation $conversation, string $body, string $rule): void
    {
        $message = DB::transaction(function () use ($conversation, $body, $rule): ?ConversationMessage {
            $locked = $this->lockedConversation($conversation, throwWhenClosed: false);

            if ($locked === null) {
                return null;
            }

            $trigger = $this->lastCustomerMessage($locked);

            // Re-checked under the lock. A member of staff replying in the
            // moment between the resolver's decision and this write must win:
            // a canned answer landing on top of a real one reads as PISFA not
            // paying attention.
            if ($trigger === null || $this->autoReply->for($locked, $trigger) === null) {
                return null;
            }

            $sendsThroughProvider = $locked->channel->usesProvider();

            $message = $this->write($locked, [
                'author_type' => MessageAuthorType::Bot,
                'author_user_id' => null,
                'channel' => $locked->channel,
                'body' => $body,
                'is_internal_note' => false,
                'delivery_status' => $sendsThroughProvider
                    ? MessageDeliveryStatus::Pending
                    : MessageDeliveryStatus::Delivered,
                'delivered_at' => $sendsThroughProvider ? null : now(),
            ]);

            $locked->forceFill([
                'last_message_at' => $message->created_at,
                'last_auto_reply_at' => now(),
            ])->save();

            $this->auditLogger->record(
                event: 'conversation.auto_replied',
                auditable: $locked,
                newValues: ['rule' => $rule, 'reference' => $locked->reference],
            );

            return $message;
        }, 3);

        if ($message !== null && $conversation->channel->usesProvider()) {
            $this->deliver($conversation->fresh() ?? $conversation, $message);
        }
    }

    /**
     * Hands an outbound message to the transport and records what came back.
     *
     * A provider failure marks the message failed rather than throwing: the
     * reply stays in the thread where staff can see it did not go, and can be
     * sent again.
     */
    public function deliver(Conversation $conversation, ConversationMessage $message): void
    {
        $to = $conversation->contact_phone;

        if ($to === null) {
            $this->recordDelivery($message, TransportResult::failed('No phone number to send to.'));

            return;
        }

        $result = $this->transports->resolve()->send($message, $to);

        $this->recordDelivery($message, $result);
    }

    private function recordDelivery(ConversationMessage $message, TransportResult $result): void
    {
        $message->forceFill($result->accepted
            ? [
                'delivery_status' => MessageDeliveryStatus::Sent,
                'sent_at' => now(),
                'provider_message_id' => $result->providerMessageId,
                'failure_reason' => null,
            ]
            : [
                'delivery_status' => MessageDeliveryStatus::Failed,
                'failure_reason' => $result->failureReason,
            ])->save();
    }

    /**
     * What happens once a customer's message is safely written.
     *
     * The bot is evaluated first so that an automatic answer is not held up by
     * a mail server, and staff are told either way — an automatic reply is a
     * holding message, not somebody dealing with the enquiry.
     */
    private function afterCustomerMessage(Conversation $conversation, ConversationMessage $message): void
    {
        $reply = $this->autoReply->for($conversation, $message);

        if ($reply !== null) {
            $this->fromBot($conversation, $reply['body'], $reply['rule']);
        }

        $this->notifyStaff($conversation, $message);
    }

    private function notifyStaff(Conversation $conversation, ConversationMessage $message): void
    {
        $assignee = $conversation->assignee;

        if ($assignee === null || ! MessagingAccess::canHandle($assignee)) {
            // An unassigned thread is watched from the inbox rather than
            // emailed to everybody: a notification per staff member per message
            // is how an inbox gets muted.
            return;
        }

        $assignee->notify(new CustomerMessageReceivedNotification(
            reference: $conversation->reference,
            contactName: $conversation->contact_name,
            channel: $conversation->channel->label(),
            preview: mb_substr($message->body, 0, 160),
            url: route('admin.inbox.show', $conversation->reference),
        ));
    }

    /** @param array<string, mixed> $attributes */
    private function write(Conversation $conversation, array $attributes): ConversationMessage
    {
        $message = new ConversationMessage;
        $message->forceFill(array_merge($attributes, [
            'conversation_id' => $conversation->getKey(),
        ]))->save();

        return $message;
    }

    private function lockedConversation(Conversation $conversation, bool $throwWhenClosed = true): ?Conversation
    {
        $locked = Conversation::query()
            ->whereKey($conversation->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        if (! $locked->acceptsMessages()) {
            if (! $throwWhenClosed) {
                return null;
            }

            throw ValidationException::withMessages([
                'body' => 'This conversation is closed. Start a new one to carry on.',
            ]);
        }

        return $locked;
    }

    private function replayed(Conversation $conversation, ?string $idempotencyKey): ?ConversationMessage
    {
        if ($idempotencyKey === null) {
            return null;
        }

        return ConversationMessage::query()
            ->where('conversation_id', $conversation->getKey())
            ->where('idempotency_key', $idempotencyKey)
            ->lockForUpdate()
            ->first();
    }

    private function lastCustomerMessage(Conversation $conversation): ?ConversationMessage
    {
        return ConversationMessage::query()
            ->where('conversation_id', $conversation->getKey())
            ->where('author_type', MessageAuthorType::Customer->value)
            ->latest('id')
            ->first();
    }

    private function validatedBody(string $body): string
    {
        $body = trim($body);

        Validator::make(
            ['body' => $body],
            ['body' => ['required', 'string', 'min:1', 'max:'.(int) config('messaging.chat.max_message_length', 2000)]],
        )->validate();

        return $body;
    }
}
