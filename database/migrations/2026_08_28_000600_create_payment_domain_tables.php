<?php

use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 40)->unique();

            // The service being paid for: a tour, hire, transfer, import, or
            // invoice. Nullable only for an unallocated on-account receipt.
            $table->nullableMorphs('payable');

            $table->foreignId('customer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('provider', 32);
            $table->string('status', 32)->default(PaymentStatus::Pending->value);

            // Amount charged, in the currency the customer actually pays in.
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);

            // The same amount converted to the reporting base currency at the
            // rate in force when the intent was created. Stored so a later rate
            // change can never retroactively alter historic revenue.
            $table->unsignedBigInteger('base_amount_minor');
            $table->char('base_currency', 3);
            $table->unsignedBigInteger('exchange_rate_ppm')->default(1_000_000);

            // Running total of completed refunds. Kept on the row so refundable
            // headroom is a single locked read rather than an aggregate.
            $table->unsignedBigInteger('refunded_amount_minor')->default(0);

            $table->string('provider_reference', 191)->nullable();
            $table->string('provider_transaction_id', 191)->nullable();

            // Guests have no user id to deduplicate against, so the owner hash
            // is an HMAC over the payer identity, mirroring the booking domains.
            $table->char('idempotency_owner_hash', 64);
            $table->uuid('idempotency_key');

            $table->text('failure_reason')->nullable();
            $table->json('metadata')->nullable();

            $table->timestamp('expires_at')->nullable();
            $table->timestamp('initiated_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(['idempotency_owner_hash', 'idempotency_key'], 'payments_idempotency_unique');

            // A provider's own transaction id must map to at most one payment,
            // which is the last line of defence against a duplicate webhook
            // creating a second settlement.
            $table->unique(['provider', 'provider_transaction_id'], 'payments_provider_transaction_unique');

            $table->index(['status', 'created_at'], 'payments_status_created_index');
            $table->index(['customer_id', 'status'], 'payments_customer_status_index');
            $table->index(['provider', 'status'], 'payments_provider_status_index');
            $table->index(['paid_at'], 'payments_paid_at_index');
        });

        Schema::create('payment_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_id')->constrained('payments')->cascadeOnDelete();
            $table->morphs('allocatable');
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->foreignId('allocated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // One payment allocates to a given target at most once, so a retry
            // cannot credit the same booking twice.
            $table->unique(
                ['payment_id', 'allocatable_type', 'allocatable_id'],
                'payment_allocations_unique',
            );
            $table->index(['allocatable_type', 'allocatable_id'], 'payment_allocations_target_index');
        });

        Schema::create('refunds', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 40)->unique();
            $table->foreignId('payment_id')->constrained('payments')->cascadeOnDelete();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('status', 24)->default(RefundStatus::Pending->value);
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->text('reason');
            $table->string('provider_refund_id', 191)->nullable();
            $table->text('failure_reason')->nullable();
            $table->json('metadata')->nullable();

            $table->uuid('idempotency_key');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['payment_id', 'idempotency_key'], 'refunds_idempotency_unique');
            $table->index(['status', 'created_at'], 'refunds_status_created_index');
        });

        Schema::create('payment_webhook_events', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 32);

            // The provider's own event identifier. Unique per provider, so a
            // replayed delivery is rejected by the database before any handler
            // runs — not merely by an application check that could race.
            $table->string('event_id', 191);

            $table->string('event_type', 120)->nullable();
            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->boolean('signature_verified')->default(false);
            $table->char('payload_sha256', 64);

            // Redacted payload. Provider responses may carry PAN fragments,
            // tokens, and phone numbers; AuditLogger-style redaction is applied
            // before anything is written here.
            $table->json('payload')->nullable();

            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->text('processing_error')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'event_id'], 'payment_webhook_events_unique');
            $table->index(['provider', 'processed_at'], 'payment_webhook_events_pending_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_webhook_events');
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('payment_allocations');
        Schema::dropIfExists('payments');
    }
};
