<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Live chat and WhatsApp.
 *
 * One conversation may span both channels: somebody starts on the website and
 * carries on over WhatsApp, and staff should see a single thread rather than
 * two half-conversations. The channel is therefore recorded per message as well
 * as on the conversation, so a reply can go back the way the last message came.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 40)->unique();
            $table->string('status', 32)->index();
            $table->string('channel', 24);

            // Nullable throughout: a visitor may open a chat, and somebody may
            // message the WhatsApp number, without ever holding an account.
            $table->foreignId('customer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('contact_name', 180);
            $table->string('contact_email', 254)->nullable();

            // The WhatsApp address. Normalised to digits on write so the same
            // person is one conversation however they type their number.
            $table->string('contact_phone', 40)->nullable();

            $table->string('subject', 200)->nullable();

            // What the conversation is about, when it is about something: a
            // booking, an invoice, a listing. Nullable because most chats are
            // general enquiries with nothing to point at yet.
            $table->nullableMorphs('subject');

            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('last_customer_message_at')->nullable();
            $table->timestamp('last_staff_message_at')->nullable();

            // When the bot last answered, so it cannot answer every message in
            // a rapid burst — see config('messaging.bot.cooldown_minutes').
            $table->timestamp('last_auto_reply_at')->nullable();

            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->string('closure_reason', 255)->nullable();

            $table->char('idempotency_owner_hash', 64)->nullable();
            $table->uuid('idempotency_key')->nullable();

            $table->timestamps();

            // A guest opening the same chat twice from a retried form gets one
            // conversation, enforced by the database rather than by a
            // read-then-write check that two requests could both pass.
            $table->unique(['idempotency_owner_hash', 'idempotency_key'], 'conversations_idempotency_unique');

            $table->index(['status', 'last_message_at'], 'conversations_queue_index');
            $table->index(['assigned_to_user_id', 'status'], 'conversations_assignee_index');
            $table->index(['customer_id', 'status'], 'conversations_customer_index');

            // Finding the live thread for an inbound WhatsApp number, which is
            // the hot path on every inbound webhook.
            $table->index(['contact_phone', 'status'], 'conversations_phone_index');
        });

        Schema::create('conversation_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();

            $table->string('author_type', 24);
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('channel', 24);
            $table->text('body');

            // Staff-only. Never rendered on a customer surface and never sent
            // to a provider — the same rule the sales enquiry notes follow.
            $table->boolean('is_internal_note')->default(false);

            $table->string('delivery_status', 24)->index();
            $table->string('failure_reason', 255)->nullable();

            // The provider's own message id, once it has one. Unique so a
            // status callback can find exactly one message, and so a retried
            // send cannot produce two rows claiming the same provider message.
            $table->string('provider_message_id', 191)->nullable();

            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();

            // The sender's own key for this message. The chat widget retries on
            // a flaky connection, and a retry must not post the same sentence
            // twice. Scoped to the conversation, which already pins who the
            // sender is, so two people cannot collide on a shared key.
            $table->uuid('idempotency_key')->nullable();

            $table->timestamps();

            $table->unique('provider_message_id', 'conversation_messages_provider_id_unique');
            $table->unique(['conversation_id', 'idempotency_key'], 'conversation_messages_idempotency_unique');
            $table->index(['conversation_id', 'id'], 'conversation_messages_thread_index');
            $table->index(['delivery_status', 'created_at'], 'conversation_messages_delivery_index');
        });

        Schema::create('messaging_webhook_events', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 32);

            // The provider's event identifier. Unique per provider, so a
            // replayed delivery is refused by the database before any handler
            // runs, rather than by a check two concurrent requests could pass.
            $table->string('event_id', 191);

            $table->string('event_type', 120)->nullable();
            $table->foreignId('conversation_id')->nullable()->constrained('conversations')->nullOnDelete();
            $table->foreignId('conversation_message_id')->nullable()
                ->constrained('conversation_messages')->nullOnDelete();

            $table->boolean('signature_verified')->default(false);

            // A digest, not the body. Enough to tell two deliveries apart in an
            // investigation without keeping customer message text in a second
            // place that has its own retention rules.
            $table->char('payload_sha256', 64);

            $table->string('result', 40)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'event_id'], 'messaging_webhook_provider_event_unique');
            $table->index(['provider', 'processed_at'], 'messaging_webhook_processed_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messaging_webhook_events');
        Schema::dropIfExists('conversation_messages');
        Schema::dropIfExists('conversations');
    }
};
