<?php

namespace Tests\Feature\Messaging;

use App\Actions\Messaging\PostMessage;
use App\Enums\AccountStatus;
use App\Enums\ConversationChannel;
use App\Enums\ConversationStatus;
use App\Enums\MessageAuthorType;
use App\Enums\MessageDeliveryStatus;
use App\Enums\UserRole;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\MessagingWebhookEvent;
use App\Models\User;
use App\Services\Messaging\MessagingTransportRegistry;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeMessagingTransport;
use Tests\TestCase;

class WhatsAppWebhookTest extends TestCase
{
    use RefreshDatabase;

    private FakeMessagingTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo('2026-08-20 07:00:00');
        Notification::fake();

        $this->transport = new FakeMessagingTransport;
        $registry = new MessagingTransportRegistry;
        $registry->register($this->transport);
        $this->app->instance(MessagingTransportRegistry::class, $registry);
    }

    /** @param array<string, mixed> $payload */
    private function deliver(array $payload, ?string $signature = null): TestResponse
    {
        $raw = json_encode($payload, JSON_THROW_ON_ERROR);

        return $this->call(
            'POST',
            route('webhooks.whatsapp.receive'),
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_HUB_SIGNATURE_256' => $signature ?? FakeMessagingTransport::signatureFor($raw),
            ],
            $raw,
        );
    }

    /** @return array<string, mixed> */
    private function inboundPayload(
        string $from = '256700111222',
        string $body = 'Hello, do you have a car available?',
        string $id = 'wamid.inbound.1',
    ): array {
        return [
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'contacts' => [['profile' => ['name' => 'Grace Nakato']]],
                        'messages' => [[
                            'id' => $id,
                            'from' => $from,
                            'type' => 'text',
                            'text' => ['body' => $body],
                        ]],
                    ],
                ]],
            ]],
        ];
    }

    /** @return array<string, mixed> */
    private function statusPayload(string $providerId, string $status, ?string $error = null): array
    {
        $entry = ['id' => $providerId, 'status' => $status];

        if ($error !== null) {
            $entry['errors'] = [['title' => $error]];
        }

        return ['entry' => [['changes' => [['value' => ['statuses' => [$entry]]]]]]];
    }

    // ---------------------------------------------------------------------
    // Signature verification
    // ---------------------------------------------------------------------

    public function test_an_unsigned_delivery_is_rejected(): void
    {
        $raw = json_encode($this->inboundPayload(), JSON_THROW_ON_ERROR);

        $this->call('POST', route('webhooks.whatsapp.receive'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], $raw)->assertStatus(400);

        $this->assertSame(0, Conversation::query()->count());
    }

    public function test_a_forged_signature_is_rejected(): void
    {
        $this->deliver($this->inboundPayload(), 'sha256='.str_repeat('a', 64))->assertStatus(400);

        $this->assertSame(0, Conversation::query()->count());
    }

    public function test_a_rejected_delivery_records_no_event_row(): void
    {
        // An unauthenticated caller must not be able to fill a table by posting
        // rubbish at the endpoint.
        $this->deliver($this->inboundPayload(), 'sha256=wrong')->assertStatus(400);

        $this->assertSame(0, MessagingWebhookEvent::query()->count());
    }

    public function test_the_subscription_handshake_needs_the_right_token(): void
    {
        config()->set('messaging.whatsapp.verify_token', 'the-configured-token');

        $this->get(route('webhooks.whatsapp.verify', [
            'hub_verify_token' => 'the-wrong-token',
            'hub_challenge' => '12345',
        ]))->assertForbidden();

        $this->get(route('webhooks.whatsapp.verify', [
            'hub_verify_token' => 'the-configured-token',
            'hub_challenge' => '12345',
        ]))->assertOk()->assertSee('12345');
    }

    public function test_the_handshake_is_refused_when_no_token_is_configured(): void
    {
        config()->set('messaging.whatsapp.verify_token', '');

        $this->get(route('webhooks.whatsapp.verify', [
            'hub_verify_token' => '',
            'hub_challenge' => '12345',
        ]))->assertForbidden();
    }

    // ---------------------------------------------------------------------
    // Inbound messages
    // ---------------------------------------------------------------------

    public function test_an_inbound_message_opens_a_conversation(): void
    {
        $this->deliver($this->inboundPayload())->assertOk();

        $conversation = Conversation::query()->firstOrFail();

        $this->assertSame(ConversationChannel::WhatsApp, $conversation->channel);
        $this->assertSame(ConversationStatus::Open, $conversation->status);
        $this->assertSame('256700111222', $conversation->contact_phone);
        $this->assertSame('Grace Nakato', $conversation->contact_name);
        $this->assertSame(1, $conversation->messages()->where('author_type', MessageAuthorType::Customer->value)->count());
    }

    public function test_a_second_message_from_the_same_number_lands_in_the_same_thread(): void
    {
        $this->deliver($this->inboundPayload(id: 'wamid.inbound.1'))->assertOk();
        $this->deliver($this->inboundPayload(body: 'Still there?', id: 'wamid.inbound.2'))->assertOk();

        $this->assertSame(1, Conversation::query()->count());
        $this->assertSame(
            2,
            ConversationMessage::query()->where('author_type', MessageAuthorType::Customer->value)->count(),
        );
    }

    public function test_a_replayed_delivery_is_refused_by_the_database(): void
    {
        $payload = $this->inboundPayload();

        $this->deliver($payload)->assertOk()->assertSee('message_recorded');
        $this->deliver($payload)->assertOk()->assertSee('duplicate');

        $this->assertSame(1, Conversation::query()->count());
        $this->assertSame(
            1,
            ConversationMessage::query()->where('author_type', MessageAuthorType::Customer->value)->count(),
        );
    }

    public function test_the_database_refuses_two_messages_with_one_provider_id(): void
    {
        $conversation = Conversation::factory()->whatsapp()->create();

        ConversationMessage::factory()->create([
            'conversation_id' => $conversation->getKey(),
            'provider_message_id' => 'wamid.duplicate',
        ]);

        $this->expectException(QueryException::class);

        ConversationMessage::factory()->create([
            'conversation_id' => $conversation->getKey(),
            'provider_message_id' => 'wamid.duplicate',
        ]);
    }

    public function test_an_inbound_message_never_claims_a_customer_account(): void
    {
        // A phone number is not proof of identity. Attaching a stranger's
        // messages to a real customer's record on a number match would be a
        // data breach dressed up as a convenience.
        $customer = User::factory()->create([
            'role' => UserRole::Customer,
            'status' => AccountStatus::Active,
            'email_verified_at' => now(),
            'phone' => '+256700111222',
        ]);

        $this->deliver($this->inboundPayload())->assertOk();

        $conversation = Conversation::query()->firstOrFail();

        $this->assertNull($conversation->customer_id);
        $this->assertTrue($conversation->isGuest());
        $this->assertNotSame($customer->getKey(), $conversation->customer_id);
    }

    public function test_a_non_text_message_is_recorded_rather_than_dropped(): void
    {
        $payload = $this->inboundPayload();
        unset($payload['entry'][0]['changes'][0]['value']['messages'][0]['text']);
        $payload['entry'][0]['changes'][0]['value']['messages'][0]['type'] = 'image';

        $this->deliver($payload)->assertOk();

        $message = ConversationMessage::query()->firstOrFail();

        $this->assertStringContainsString('Attachment', $message->body);
    }

    public function test_a_closed_thread_is_not_reused_by_a_new_message(): void
    {
        $closed = Conversation::factory()->whatsapp()->closed()->create([
            'contact_phone' => '256700111222',
        ]);

        $this->deliver($this->inboundPayload())->assertOk();

        $this->assertSame(2, Conversation::query()->count());
        $this->assertSame(ConversationStatus::Closed, $closed->refresh()->status);
    }

    public function test_a_payload_this_deployment_cannot_use_is_answered_with_200(): void
    {
        // A provider that receives an error retries. An unusable payload would
        // fail identically every time, turning one bad message into a stream.
        $this->deliver(['entry' => [['changes' => [['value' => ['nothing' => true]]]]]])
            ->assertOk()
            ->assertSee('unsupported_payload');
    }

    // ---------------------------------------------------------------------
    // Delivery receipts
    // ---------------------------------------------------------------------

    private function sentMessage(): ConversationMessage
    {
        $staff = User::factory()->create([
            'role' => UserRole::Staff,
            'status' => AccountStatus::Active,
            'email_verified_at' => now(),
        ]);

        $conversation = Conversation::factory()->whatsapp()->create();

        return app(PostMessage::class)->fromStaff($staff, $conversation, 'Yes, we have one available.')->refresh();
    }

    public function test_a_delivery_receipt_advances_the_message(): void
    {
        $message = $this->sentMessage();

        $this->deliver($this->statusPayload((string) $message->provider_message_id, 'delivered'))
            ->assertOk()
            ->assertSee('delivery_updated');

        $this->assertSame(MessageDeliveryStatus::Delivered, $message->refresh()->delivery_status);
    }

    public function test_a_late_receipt_never_drags_a_read_message_backwards(): void
    {
        $message = $this->sentMessage();
        $providerId = (string) $message->provider_message_id;

        // Out of order on purpose: the read receipt arrives first.
        $this->deliver($this->statusPayload($providerId, 'read'))->assertOk();
        $this->assertSame(MessageDeliveryStatus::Read, $message->refresh()->delivery_status);

        $this->deliver($this->statusPayload($providerId, 'delivered'))
            ->assertOk()
            ->assertSee('no_change');

        // Still read. A customer who has demonstrably seen the message must not
        // be shown to staff as not having seen it.
        $this->assertSame(MessageDeliveryStatus::Read, $message->refresh()->delivery_status);
    }

    public function test_a_failure_after_delivery_is_not_believed(): void
    {
        $message = $this->sentMessage();
        $providerId = (string) $message->provider_message_id;

        $this->deliver($this->statusPayload($providerId, 'delivered'))->assertOk();
        $this->deliver($this->statusPayload($providerId, 'failed', 'Recipient unavailable'))->assertOk();

        // The provider already told us it arrived, which is the fact that
        // matters.
        $this->assertSame(MessageDeliveryStatus::Delivered, $message->refresh()->delivery_status);
    }

    public function test_a_failure_before_delivery_is_recorded_with_its_reason(): void
    {
        $message = $this->sentMessage();

        $this->deliver($this->statusPayload(
            (string) $message->provider_message_id,
            'failed',
            'Recipient is not on WhatsApp',
        ))->assertOk();

        $message->refresh();

        $this->assertSame(MessageDeliveryStatus::Failed, $message->delivery_status);
        $this->assertSame('Recipient is not on WhatsApp', $message->failure_reason);
    }

    public function test_a_failed_message_is_not_resurrected_by_a_later_receipt(): void
    {
        $message = $this->sentMessage();
        $providerId = (string) $message->provider_message_id;

        $this->deliver($this->statusPayload($providerId, 'failed', 'Recipient is not on WhatsApp'))->assertOk();
        $this->deliver($this->statusPayload($providerId, 'sent'))->assertOk();

        $this->assertSame(MessageDeliveryStatus::Failed, $message->refresh()->delivery_status);
    }

    public function test_a_receipt_for_a_message_we_never_sent_is_recorded_and_ignored(): void
    {
        $this->deliver($this->statusPayload('wamid.unknown', 'delivered'))
            ->assertOk()
            ->assertSee('unknown_message');
    }

    public function test_an_unmapped_status_is_ignored_rather_than_guessed(): void
    {
        $message = $this->sentMessage();

        $this->deliver($this->statusPayload((string) $message->provider_message_id, 'deleted'))
            ->assertOk()
            ->assertSee('unmapped_status');

        $this->assertSame(MessageDeliveryStatus::Sent, $message->refresh()->delivery_status);
    }

    // ---------------------------------------------------------------------
    // Bookkeeping
    // ---------------------------------------------------------------------

    public function test_every_accepted_delivery_leaves_an_event_with_its_outcome(): void
    {
        $this->deliver($this->inboundPayload())->assertOk();

        $event = MessagingWebhookEvent::query()->firstOrFail();

        $this->assertTrue($event->signature_verified);
        $this->assertSame('message_recorded', $event->result);
        $this->assertNotNull($event->processed_at);
        $this->assertSame(64, strlen($event->payload_sha256));
    }

    public function test_the_event_row_keeps_a_digest_and_not_the_message_text(): void
    {
        $this->deliver($this->inboundPayload(body: 'My passport number is A1234567.'))->assertOk();

        $event = MessagingWebhookEvent::query()->firstOrFail();

        $this->assertStringNotContainsString('A1234567', json_encode($event->toArray(), JSON_THROW_ON_ERROR));
    }

    public function test_the_bot_answers_an_inbound_whatsapp_message(): void
    {
        $this->deliver($this->inboundPayload(body: 'Do you do car hire?'))->assertOk();

        $conversation = Conversation::query()->firstOrFail();
        $bot = $conversation->messages()->where('author_type', MessageAuthorType::Bot->value)->first();

        $this->assertNotNull($bot);
        // The automatic answer goes back out through the transport, because
        // WhatsApp is not delivered by polling.
        $this->assertNotEmpty($this->transport->sent);
    }

    public function test_the_log_transport_refuses_every_inbound_delivery(): void
    {
        // The default transport signs nothing, so a deployment that has not
        // configured WhatsApp cannot be fed messages by anybody who finds the
        // webhook URL.
        $this->app->instance(MessagingTransportRegistry::class, new MessagingTransportRegistry);

        $this->deliver($this->inboundPayload())->assertStatus(400);

        $this->assertSame(0, Conversation::query()->count());
    }
}
