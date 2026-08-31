<?php

namespace Tests\Feature\Messaging;

use App\Actions\Messaging\PostMessage;
use App\Actions\Messaging\StartConversation;
use App\Actions\Messaging\TransitionConversation;
use App\Enums\AccountStatus;
use App\Enums\ConversationChannel;
use App\Enums\ConversationStatus;
use App\Enums\MessageAuthorType;
use App\Enums\MessageDeliveryStatus;
use App\Enums\UserRole;
use App\Http\Middleware\EnsureTwoFactorAuthenticationIsConfigured;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\User;
use App\Notifications\Messaging\CustomerMessageReceivedNotification;
use App\Services\Messaging\MessagingTransportRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\FakeMessagingTransport;
use Tests\TestCase;

class LiveChatTest extends TestCase
{
    use RefreshDatabase;

    private FakeMessagingTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        // A Thursday at 10am Kampala time: inside business hours, so the away
        // message does not fire unless a test asks for it.
        $this->travelTo('2026-08-20 07:00:00');
        Notification::fake();

        // The whole suite runs on a fake transport. No test ever needs a real
        // WhatsApp credential.
        $this->transport = new FakeMessagingTransport;
        $registry = new MessagingTransportRegistry;
        $registry->register($this->transport);
        $this->app->instance(MessagingTransportRegistry::class, $registry);
    }

    /** @param array<string, mixed> $attributes */
    private function user(UserRole $role, array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'role' => $role,
            'status' => AccountStatus::Active,
            'email_verified_at' => now(),
            'phone' => '+256700'.fake()->unique()->numerify('######'),
        ], $attributes));
    }

    private function staff(): User
    {
        return $this->user(UserRole::Staff);
    }

    private function customer(): User
    {
        return $this->user(UserRole::Customer);
    }

    /** @param array<string, mixed> $overrides */
    private function guestChat(array $overrides = []): Conversation
    {
        return app(StartConversation::class)->execute(
            null,
            array_merge([
                'contact_name' => 'Grace Nakato',
                'contact_email' => 'grace@example.com',
                'channel' => ConversationChannel::Web->value,
                'message' => 'Hello, I would like to know more.',
            ], $overrides),
            (string) Str::uuid(),
        );
    }

    // ---------------------------------------------------------------------
    // Starting a conversation
    // ---------------------------------------------------------------------

    public function test_a_guest_can_start_a_conversation_without_an_account(): void
    {
        $conversation = $this->guestChat();

        $this->assertTrue($conversation->isGuest());
        $this->assertNull($conversation->customer_id);
        $this->assertSame(ConversationStatus::Open, $conversation->status);
        $this->assertStringStartsWith('CHT-', $conversation->reference);
        $this->assertSame(1, $conversation->messages()->count());
    }

    public function test_a_signed_in_customer_cannot_supply_a_different_identity(): void
    {
        $customer = $this->customer();

        $conversation = app(StartConversation::class)->execute(
            $customer,
            [
                'contact_name' => 'Somebody Else',
                'contact_email' => 'impostor@example.com',
                'channel' => ConversationChannel::Web->value,
                'message' => 'Hello.',
            ],
            (string) Str::uuid(),
        );

        $this->assertSame($customer->name, $conversation->contact_name);
        $this->assertSame($customer->email, $conversation->contact_email);
        $this->assertSame($customer->getKey(), $conversation->customer_id);
    }

    public function test_a_replayed_request_produces_one_conversation_and_one_message(): void
    {
        $key = (string) Str::uuid();

        $attributes = [
            'contact_name' => 'Grace Nakato',
            'contact_email' => 'grace@example.com',
            'channel' => ConversationChannel::Web->value,
            'message' => 'Hello, I would like to know more.',
        ];

        $first = app(StartConversation::class)->execute(null, $attributes, $key);
        $second = app(StartConversation::class)->execute(null, $attributes, $key);

        $this->assertTrue($first->is($second));
        $this->assertSame(1, Conversation::query()->count());
        $this->assertSame(1, ConversationMessage::query()->count());
    }

    public function test_two_different_guests_replaying_the_same_key_do_not_collide(): void
    {
        $key = (string) Str::uuid();

        $first = app(StartConversation::class)->execute(null, [
            'contact_name' => 'Grace Nakato',
            'contact_email' => 'grace@example.com',
            'channel' => ConversationChannel::Web->value,
            'message' => 'Hello.',
        ], $key);

        $second = app(StartConversation::class)->execute(null, [
            'contact_name' => 'Peter Okello',
            'contact_email' => 'peter@example.com',
            'channel' => ConversationChannel::Web->value,
            'message' => 'Hello.',
        ], $key);

        $this->assertFalse($first->is($second));
        $this->assertSame(2, Conversation::query()->count());
    }

    public function test_a_whatsapp_conversation_needs_a_phone_number(): void
    {
        $this->expectException(ValidationException::class);

        app(StartConversation::class)->execute(null, [
            'contact_name' => 'Grace Nakato',
            'contact_email' => 'grace@example.com',
            'channel' => ConversationChannel::WhatsApp->value,
            'message' => 'Hello.',
        ], (string) Str::uuid());
    }

    public function test_a_conversation_with_no_way_to_reply_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        app(StartConversation::class)->execute(null, [
            'contact_name' => 'Grace Nakato',
            'channel' => ConversationChannel::Web->value,
            'message' => 'Hello.',
        ], (string) Str::uuid());
    }

    public function test_a_phone_number_is_normalised_so_one_person_is_one_thread(): void
    {
        $conversation = app(StartConversation::class)->execute(null, [
            'contact_name' => 'Grace Nakato',
            'contact_phone' => '+256 700 (000) 111',
            'channel' => ConversationChannel::WhatsApp->value,
            'message' => 'Hello.',
        ], (string) Str::uuid());

        $this->assertSame('+256700000111', $conversation->contact_phone);
    }

    // ---------------------------------------------------------------------
    // Messages
    // ---------------------------------------------------------------------

    public function test_a_retried_send_does_not_post_the_same_sentence_twice(): void
    {
        $conversation = $this->guestChat();
        $key = (string) Str::uuid();

        // Deliberately matches no keyword, so the count measures the retry and
        // nothing else.
        $first = app(PostMessage::class)->fromCustomer($conversation, 'Thank you very much.', $key);
        $second = app(PostMessage::class)->fromCustomer($conversation, 'Thank you very much.', $key);

        $this->assertTrue($first->is($second));
        $this->assertSame(2, $conversation->messages()->count());
    }

    public function test_the_database_refuses_a_duplicate_message_key(): void
    {
        $conversation = $this->guestChat();
        $key = (string) Str::uuid();

        ConversationMessage::factory()->create([
            'conversation_id' => $conversation->getKey(),
            'idempotency_key' => $key,
        ]);

        $this->expectException(QueryException::class);

        ConversationMessage::factory()->create([
            'conversation_id' => $conversation->getKey(),
            'idempotency_key' => $key,
        ]);
    }

    public function test_a_staff_reply_puts_the_thread_in_the_customers_court(): void
    {
        $conversation = $this->guestChat();
        $staff = $this->staff();

        app(PostMessage::class)->fromStaff($staff, $conversation, 'Yes, we are open until six.');

        $conversation->refresh();

        $this->assertSame(ConversationStatus::AwaitingCustomer, $conversation->status);
        $this->assertSame($staff->getKey(), $conversation->assigned_to_user_id);
        $this->assertNotNull($conversation->last_staff_message_at);
    }

    public function test_the_customer_writing_again_returns_the_thread_to_the_queue(): void
    {
        $conversation = $this->guestChat();
        app(PostMessage::class)->fromStaff($this->staff(), $conversation, 'Yes, we are open until six.');

        app(PostMessage::class)->fromCustomer($conversation->refresh(), 'Thank you, what are your rates?');

        $this->assertSame(ConversationStatus::Open, $conversation->refresh()->status);
    }

    public function test_a_resolved_thread_reopens_when_the_customer_writes(): void
    {
        $conversation = $this->guestChat();
        $staff = $this->staff();

        app(PostMessage::class)->fromStaff($staff, $conversation, 'All sorted.');
        app(TransitionConversation::class)->resolve($staff, $conversation->refresh());

        $this->assertSame(ConversationStatus::Resolved, $conversation->refresh()->status);

        app(PostMessage::class)->fromCustomer($conversation->refresh(), 'One more thing.');

        $conversation->refresh();

        $this->assertSame(ConversationStatus::Open, $conversation->status);
        $this->assertNull($conversation->resolved_at);
    }

    public function test_a_closed_thread_takes_no_further_messages(): void
    {
        $conversation = $this->guestChat();
        $staff = $this->staff();

        app(PostMessage::class)->fromStaff($staff, $conversation, 'Handled by telephone.');
        app(TransitionConversation::class)->close($staff, $conversation->refresh(), 'Dealt with by telephone.');

        $this->expectException(ValidationException::class);

        app(PostMessage::class)->fromCustomer($conversation->refresh(), 'Hello again.');
    }

    public function test_an_internal_note_is_never_shown_to_the_customer(): void
    {
        $conversation = $this->guestChat();
        $staff = $this->staff();

        app(PostMessage::class)->fromStaff($staff, $conversation, 'Chased on the phone, no answer.', internalNote: true);

        $visible = $conversation->messages()->visibleToCustomer()->get();

        $this->assertSame(1, $visible->count());
        $this->assertStringNotContainsString('Chased on the phone', $visible->implode('body', ' '));
    }

    public function test_an_internal_note_does_not_move_the_conversation_on(): void
    {
        $conversation = $this->guestChat();

        app(PostMessage::class)->fromStaff($this->staff(), $conversation, 'Looks like a duplicate.', internalNote: true);

        $conversation->refresh();

        $this->assertSame(ConversationStatus::Open, $conversation->status);
        $this->assertNull($conversation->last_staff_message_at);
    }

    public function test_a_customer_cannot_reply_as_staff(): void
    {
        $conversation = $this->guestChat();

        $this->expectException(AuthorizationException::class);

        app(PostMessage::class)->fromStaff($this->customer(), $conversation, 'Sure, no problem.');
    }

    public function test_a_suspended_member_of_staff_cannot_reply(): void
    {
        $conversation = $this->guestChat();
        $staff = $this->staff();
        $staff->forceFill(['status' => AccountStatus::Suspended])->save();

        $this->expectException(AuthorizationException::class);

        app(PostMessage::class)->fromStaff($staff, $conversation, 'Hello.');
    }

    public function test_the_assignee_is_told_when_the_customer_writes(): void
    {
        $conversation = $this->guestChat();
        $staff = $this->staff();

        app(TransitionConversation::class)->assign($staff, $conversation, $staff);

        app(PostMessage::class)->fromCustomer($conversation->refresh(), 'Any update?');

        Notification::assertSentTo($staff, CustomerMessageReceivedNotification::class);
    }

    public function test_an_unassigned_thread_does_not_email_anybody(): void
    {
        $conversation = $this->guestChat();

        app(PostMessage::class)->fromCustomer($conversation, 'Any update?');

        Notification::assertNothingSent();
    }

    // ---------------------------------------------------------------------
    // The automatic reply and its guards
    // ---------------------------------------------------------------------

    public function test_a_keyword_gets_an_automatic_answer(): void
    {
        $conversation = $this->guestChat(['message' => 'Do you do gorilla trekking safaris?']);

        $bot = $conversation->messages()->where('author_type', MessageAuthorType::Bot->value)->first();

        $this->assertNotNull($bot);
        $this->assertStringContainsString('safaris', $bot->body);
    }

    public function test_the_bot_never_answers_itself(): void
    {
        $conversation = $this->guestChat(['message' => 'Do you do gorilla trekking safaris?']);

        // One inbound message, one automatic answer, and nothing further no
        // matter how many times the thread is re-examined.
        $this->assertSame(
            1,
            $conversation->messages()->where('author_type', MessageAuthorType::Bot->value)->count(),
        );
        $this->assertSame(2, $conversation->messages()->count());
    }

    public function test_the_bot_answers_once_per_cooldown_however_fast_somebody_types(): void
    {
        $conversation = $this->guestChat(['message' => 'Do you do gorilla trekking safaris?']);

        app(PostMessage::class)->fromCustomer($conversation->refresh(), 'And what about car hire?');
        app(PostMessage::class)->fromCustomer($conversation->refresh(), 'Or airport pickup?');

        $this->assertSame(
            1,
            $conversation->messages()->where('author_type', MessageAuthorType::Bot->value)->count(),
        );
    }

    public function test_the_bot_answers_again_once_the_cooldown_has_passed(): void
    {
        $conversation = $this->guestChat(['message' => 'Do you do gorilla trekking safaris?']);

        $this->travel((int) config('messaging.bot.cooldown_minutes') + 1)->minutes();

        app(PostMessage::class)->fromCustomer($conversation->refresh(), 'And what about car hire?');

        $this->assertSame(
            2,
            $conversation->messages()->where('author_type', MessageAuthorType::Bot->value)->count(),
        );
    }

    public function test_the_bot_falls_silent_once_a_person_has_replied(): void
    {
        $conversation = $this->guestChat(['message' => 'Hello there.']);

        app(PostMessage::class)->fromStaff($this->staff(), $conversation, 'Hello, how can I help?');

        $this->travel((int) config('messaging.bot.cooldown_minutes') + 1)->minutes();

        app(PostMessage::class)->fromCustomer($conversation->refresh(), 'Do you do gorilla trekking safaris?');

        $this->assertSame(
            0,
            $conversation->messages()->where('author_type', MessageAuthorType::Bot->value)->count(),
        );
    }

    public function test_an_unmatched_message_inside_business_hours_gets_no_canned_reply(): void
    {
        $conversation = $this->guestChat(['message' => 'Something entirely unrelated to travel.']);

        $this->assertSame(
            0,
            $conversation->messages()->where('author_type', MessageAuthorType::Bot->value)->count(),
        );
    }

    public function test_an_unmatched_message_outside_business_hours_gets_the_away_message(): void
    {
        // 23:00 Kampala on a Thursday: closed.
        $this->travelTo('2026-08-20 20:00:00');

        $conversation = $this->guestChat(['message' => 'Something entirely unrelated to travel.']);

        $bot = $conversation->messages()->where('author_type', MessageAuthorType::Bot->value)->first();

        $this->assertNotNull($bot);
        $this->assertStringContainsString('closed', mb_strtolower($bot->body));
    }

    public function test_the_bot_can_be_switched_off_entirely(): void
    {
        config()->set('messaging.bot.enabled', false);

        $conversation = $this->guestChat(['message' => 'Do you do gorilla trekking safaris?']);

        $this->assertSame(
            0,
            $conversation->messages()->where('author_type', MessageAuthorType::Bot->value)->count(),
        );
    }

    // ---------------------------------------------------------------------
    // Delivery
    // ---------------------------------------------------------------------

    public function test_a_website_reply_is_delivered_the_moment_it_is_written(): void
    {
        $conversation = $this->guestChat();

        $message = app(PostMessage::class)->fromStaff($this->staff(), $conversation, 'Hello there.');

        $this->assertSame(MessageDeliveryStatus::Delivered, $message->delivery_status);
        $this->assertSame([], $this->transport->sent);
    }

    public function test_a_whatsapp_reply_goes_out_through_the_transport(): void
    {
        $conversation = Conversation::factory()->whatsapp()->create();

        $message = app(PostMessage::class)->fromStaff($this->staff(), $conversation, 'Hello there.');

        $this->assertCount(1, $this->transport->sent);
        $this->assertSame(MessageDeliveryStatus::Sent, $message->refresh()->delivery_status);
        $this->assertStringStartsWith('wamid.test.', (string) $message->provider_message_id);
    }

    public function test_a_provider_failure_leaves_the_reply_in_the_thread_marked_failed(): void
    {
        $this->transport->shouldFail = true;

        $conversation = Conversation::factory()->whatsapp()->create();

        $message = app(PostMessage::class)->fromStaff($this->staff(), $conversation, 'Hello there.');

        $message->refresh();

        // The reply is not lost. Staff can see it did not go, and why.
        $this->assertSame(MessageDeliveryStatus::Failed, $message->delivery_status);
        $this->assertNotNull($message->failure_reason);
        $this->assertSame(1, $conversation->messages()->where('author_type', MessageAuthorType::Staff->value)->count());
    }

    public function test_a_whatsapp_thread_with_no_number_refuses_a_reply(): void
    {
        $conversation = Conversation::factory()->whatsapp()->create(['contact_phone' => null]);

        $this->expectException(ValidationException::class);

        app(PostMessage::class)->fromStaff($this->staff(), $conversation, 'Hello there.');
    }

    // ---------------------------------------------------------------------
    // Lifecycle
    // ---------------------------------------------------------------------

    public function test_closing_requires_a_reason(): void
    {
        $conversation = $this->guestChat();

        $this->expectException(ValidationException::class);

        app(TransitionConversation::class)->close($this->staff(), $conversation, 'no');
    }

    public function test_a_closed_conversation_cannot_be_reopened(): void
    {
        $conversation = $this->guestChat();
        $staff = $this->staff();

        app(TransitionConversation::class)->close($staff, $conversation, 'Dealt with by telephone.');

        $this->expectException(ValidationException::class);

        app(TransitionConversation::class)->reopen($staff, $conversation->refresh());
    }

    public function test_a_thread_cannot_be_assigned_to_somebody_without_inbox_access(): void
    {
        $conversation = $this->guestChat();

        $this->expectException(ValidationException::class);

        app(TransitionConversation::class)->assign($this->staff(), $conversation, $this->customer());
    }

    public function test_every_conversation_status_is_reachable(): void
    {
        $conversation = $this->guestChat();
        $staff = $this->staff();

        $this->assertSame(ConversationStatus::Open, $conversation->status);

        app(PostMessage::class)->fromStaff($staff, $conversation, 'Hello there.');
        $this->assertSame(ConversationStatus::AwaitingCustomer, $conversation->refresh()->status);

        app(TransitionConversation::class)->resolve($staff, $conversation->refresh());
        $this->assertSame(ConversationStatus::Resolved, $conversation->refresh()->status);

        app(TransitionConversation::class)->close($staff, $conversation->refresh(), 'Nothing further needed.');
        $this->assertSame(ConversationStatus::Closed, $conversation->refresh()->status);
    }

    // ---------------------------------------------------------------------
    // The chat endpoints
    // ---------------------------------------------------------------------

    public function test_a_guest_can_start_and_poll_a_chat_over_http(): void
    {
        $response = $this->postJson(route('chat.start'), [
            'contact_name' => 'Grace Nakato',
            'contact_email' => 'grace@example.com',
            'message' => 'Do you do gorilla trekking safaris?',
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $response->assertCreated();
        $reference = $response->json('reference');

        $this->assertIsString($reference);

        $poll = $this->getJson(route('chat.messages', $reference));

        $poll->assertOk();
        $poll->assertJsonPath('reference', $reference);
        $this->assertGreaterThanOrEqual(2, count($poll->json('messages')));
    }

    public function test_a_guest_cannot_read_a_conversation_their_session_did_not_open(): void
    {
        $conversation = $this->guestChat();

        // A fresh session — the reference is known, but not claimed.
        $this->getJson(route('chat.messages', $conversation->reference))->assertNotFound();
    }

    public function test_a_customer_cannot_read_another_customers_conversation(): void
    {
        $conversation = $this->guestChat();
        $conversation->forceFill(['customer_id' => $this->customer()->getKey()])->save();

        $this->actingAs($this->customer())
            ->getJson(route('chat.messages', $conversation->reference))
            ->assertNotFound();
    }

    public function test_the_poll_never_returns_an_internal_note(): void
    {
        $response = $this->postJson(route('chat.start'), [
            'contact_name' => 'Grace Nakato',
            'contact_email' => 'grace@example.com',
            'message' => 'Hello.',
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $reference = (string) $response->json('reference');
        $conversation = Conversation::query()->where('reference', $reference)->firstOrFail();

        app(PostMessage::class)->fromStaff($this->staff(), $conversation, 'Probably a time waster.', internalNote: true);

        $poll = $this->getJson(route('chat.messages', $reference));

        $poll->assertOk();
        $poll->assertDontSee('time waster');
    }

    public function test_the_cursor_only_returns_what_is_new(): void
    {
        $start = $this->postJson(route('chat.start'), [
            'contact_name' => 'Grace Nakato',
            'contact_email' => 'grace@example.com',
            'message' => 'Hello.',
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $reference = (string) $start->json('reference');

        $first = $this->getJson(route('chat.messages', $reference));
        $cursor = $first->json('cursor');

        $again = $this->getJson(route('chat.messages', ['reference' => $reference, 'after' => $cursor]));

        $again->assertOk();
        $this->assertSame([], $again->json('messages'));
    }

    // ---------------------------------------------------------------------
    // The staff inbox
    // ---------------------------------------------------------------------

    public function test_the_inbox_is_closed_to_customers(): void
    {
        $this->withoutMiddleware(EnsureTwoFactorAuthenticationIsConfigured::class);

        $this->actingAs($this->customer())
            ->get(route('admin.inbox.index'))
            ->assertForbidden();
    }

    public function test_staff_can_open_and_answer_a_thread_from_the_console(): void
    {
        $this->withoutMiddleware(EnsureTwoFactorAuthenticationIsConfigured::class);

        $conversation = $this->guestChat();
        $staff = $this->staff();

        $this->actingAs($staff)
            ->get(route('admin.inbox.show', $conversation))
            ->assertOk()
            ->assertSee($conversation->reference);

        $this->actingAs($staff)
            ->post(route('admin.inbox.reply', $conversation), ['body' => 'Yes, we can help with that.'])
            ->assertRedirect();

        $this->assertSame(ConversationStatus::AwaitingCustomer, $conversation->refresh()->status);
    }

    public function test_the_inbox_list_renders(): void
    {
        $this->withoutMiddleware(EnsureTwoFactorAuthenticationIsConfigured::class);

        $this->guestChat();

        $this->actingAs($this->staff())
            ->get(route('admin.inbox.index'))
            ->assertOk();
    }
}
