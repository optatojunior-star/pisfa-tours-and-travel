<?php

namespace App\Actions\Messaging;

use App\Enums\ConversationChannel;
use App\Enums\ConversationStatus;
use App\Enums\MessageDeliveryStatus;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\MessagingWebhookEvent;
use App\Services\Messaging\MessagingTransportRegistry;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Takes a delivery from a messaging provider.
 *
 * The safety of this endpoint rests on three things, in order: the signature is
 * verified before the payload is looked at, the provider's event id is unique
 * in the database so a replay is refused rather than merely detected, and every
 * outcome is a recorded event so a silent drop is impossible to mistake for a
 * success.
 *
 * Nothing here trusts the payload for identity. A status callback is matched to
 * a message by the provider id we stored when we sent it, and an inbound
 * message is matched to a conversation by phone number — never by an id the
 * caller supplies for a record it does not own.
 */
class HandleInboundWebhook
{
    public function __construct(
        private readonly MessagingTransportRegistry $transports,
        private readonly PostMessage $postMessage,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     * @return array{handled: bool, reason: string}
     */
    public function execute(string $rawPayload, array $payload, array $headers): array
    {
        $transport = $this->transports->resolve();
        $provider = $transport->name();
        $digest = hash('sha256', $rawPayload);

        if (! $transport->verifySignature($rawPayload, $headers)) {
            // Deliberately not recorded as an event row: an unauthenticated
            // caller must not be able to fill a table by posting rubbish at
            // the endpoint.
            return ['handled' => false, 'reason' => 'invalid_signature'];
        }

        $parsed = $transport->parseWebhook($payload);

        if ($parsed === null) {
            return ['handled' => false, 'reason' => 'unsupported_payload'];
        }

        $eventId = (string) ($parsed['event_id'] ?? '');

        if (trim($eventId) === '') {
            return ['handled' => false, 'reason' => 'missing_event_id'];
        }

        try {
            $event = MessagingWebhookEvent::query()->create([
                'provider' => $provider,
                'event_id' => $eventId,
                'event_type' => (string) ($parsed['kind'] ?? 'unknown'),
                'signature_verified' => true,
                'payload_sha256' => $digest,
            ]);
        } catch (UniqueConstraintViolationException) {
            // The database refused a replay. This is the guarantee: two
            // simultaneous deliveries of the same event cannot both get past
            // it, which an application-level check could not promise.
            return ['handled' => true, 'reason' => 'duplicate'];
        }

        $result = match ($parsed['kind']) {
            'inbound' => $this->handleInbound($parsed),
            'status' => $this->handleStatus($parsed),
            default => 'ignored',
        };

        $event->forceFill([
            'result' => $result,
            'processed_at' => now(),
        ])->save();

        return ['handled' => true, 'reason' => $result];
    }

    /**
     * An inbound WhatsApp message.
     *
     * @param  array<string, mixed>  $parsed
     */
    private function handleInbound(array $parsed): string
    {
        $from = Conversation::normalisePhone($parsed['from'] ?? null);

        if ($from === null) {
            return 'no_sender';
        }

        $conversation = $this->conversationFor($from, (string) ($parsed['name'] ?? 'WhatsApp contact'));

        try {
            $this->postMessage->fromCustomer(
                conversation: $conversation,
                body: (string) $parsed['body'],
                idempotencyKey: null,
                channel: ConversationChannel::WhatsApp,
                // The provider's own message id, which is unique on the
                // messages table. A replay that somehow got past the event
                // table is stopped here by the same database guarantee rather
                // than posting the customer's words into the thread twice.
                providerMessageId: (string) ($parsed['provider_message_id'] ?? $parsed['event_id']),
            );
        } catch (UniqueConstraintViolationException) {
            return 'duplicate_message';
        }

        return 'message_recorded';
    }

    /**
     * A delivery receipt for something PISFA sent.
     *
     * @param  array<string, mixed>  $parsed
     */
    private function handleStatus(array $parsed): string
    {
        $providerId = (string) ($parsed['provider_message_id'] ?? '');
        $reported = $this->mapStatus((string) ($parsed['status'] ?? ''));

        if ($providerId === '' || $reported === null) {
            return 'unmapped_status';
        }

        return DB::transaction(function () use ($providerId, $reported, $parsed): string {
            $message = ConversationMessage::query()
                ->where('provider_message_id', $providerId)
                ->lockForUpdate()
                ->first();

            if ($message === null) {
                return 'unknown_message';
            }

            $failureReason = $parsed['failure_reason'] ?? null;

            // Advances by rank, so a callback that arrives late cannot drag a
            // message that was demonstrably read back to merely delivered.
            $changed = $message->applyDeliveryReport(
                $reported,
                is_string($failureReason) ? $failureReason : null,
            );

            if (! $changed) {
                return 'no_change';
            }

            $message->save();

            return 'delivery_updated';
        }, 3);
    }

    /**
     * Finds the live thread for a number, or opens one.
     *
     * A conversation that was closed is not reused: closing is deliberate, and
     * a new message after it is a new conversation.
     */
    private function conversationFor(string $phone, string $name): Conversation
    {
        return DB::transaction(function () use ($phone, $name): Conversation {
            $existing = Conversation::query()
                ->where('contact_phone', $phone)
                ->whereIn('status', [
                    ConversationStatus::Open->value,
                    ConversationStatus::AwaitingCustomer->value,
                    ConversationStatus::Resolved->value,
                ])
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $conversation = new Conversation;
            $conversation->forceFill([
                'reference' => 'CHT-'.Str::upper((string) Str::ulid()),
                'status' => ConversationStatus::Open,
                'channel' => ConversationChannel::WhatsApp,
                'contact_name' => trim($name) === '' ? 'WhatsApp contact' : trim($name),
                'contact_phone' => $phone,
                // Not linked to a customer account even when the number matches
                // one. A phone number is not proof of identity, and treating it
                // as such would attach a stranger's messages to a real
                // customer's record.
                'customer_id' => null,
            ])->save();

            return $conversation;
        }, 3);
    }

    private function mapStatus(string $status): ?MessageDeliveryStatus
    {
        return match (strtolower($status)) {
            'sent' => MessageDeliveryStatus::Sent,
            'delivered' => MessageDeliveryStatus::Delivered,
            'read' => MessageDeliveryStatus::Read,
            'failed' => MessageDeliveryStatus::Failed,
            default => null,
        };
    }
}
