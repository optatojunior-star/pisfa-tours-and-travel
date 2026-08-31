<?php

use App\Enums\InvoiceStatus;
use App\Enums\QuotationRequestStatus;
use App\Enums\QuotationStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Gapless-per-period numbering for quotations and invoices.
         *
         * A finance document must not have a number derived from an id or a
         * random string: auditors expect a contiguous series. The counter is
         * incremented under a row lock inside the issuing transaction, and the
         * resulting number carries its own unique index as a backstop.
         */
        Schema::create('number_sequences', function (Blueprint $table): void {
            $table->id();
            $table->string('prefix', 12);
            $table->string('period', 8);
            $table->unsignedBigInteger('next_value')->default(1);
            $table->timestamps();

            $table->unique(['prefix', 'period']);
        });

        Schema::create('quotation_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 40)->unique();

            // A guest's only credential. Long, random, never derived from the
            // reference.
            $table->char('tracking_token', 64)->unique();

            $table->foreignId('customer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('status', 24)->default(QuotationRequestStatus::New->value);
            $table->string('service', 40);

            $table->string('contact_name', 180);
            $table->string('contact_email', 254);
            $table->string('contact_phone', 40);
            $table->string('company_name', 180)->nullable();

            $table->text('details');
            $table->date('preferred_date')->nullable();
            $table->unsignedSmallInteger('party_size')->nullable();

            // What the customer says they can spend. The quotation is what
            // PISFA charges; deliberately separate columns.
            $table->unsignedBigInteger('budget_minor')->nullable();
            $table->char('budget_currency', 3)->nullable();

            $table->text('internal_notes')->nullable();
            $table->string('closure_reason', 255)->nullable();
            $table->timestamp('closed_at')->nullable();

            $table->char('idempotency_owner_hash', 64);
            $table->uuid('idempotency_key');

            $table->timestamps();

            // A repeated submit from the same browser is the same request, not
            // a second one. Enforced by the database, not by a read-then-write.
            $table->unique(['idempotency_owner_hash', 'idempotency_key'], 'quotation_requests_idem_unique');
            $table->index(['status', 'created_at']);
            $table->index('customer_id');
        });

        Schema::create('quotations', function (Blueprint $table): void {
            $table->id();
            $table->string('number', 32)->unique();
            $table->char('tracking_token', 64)->unique();

            $table->foreignId('quotation_request_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('status', 24)->default(QuotationStatus::Draft->value);

            // Snapshot of who it was addressed to. A later change to the user
            // record must not rewrite a document already sent.
            $table->string('contact_name', 180);
            $table->string('contact_email', 254);
            $table->string('contact_phone', 40);
            $table->string('company_name', 180)->nullable();

            $table->string('title', 180);
            $table->char('currency', 3);

            // Every money column is integer minor units. Tax is basis points so
            // 18% VAT is 1800 and the rate never becomes a float.
            $table->unsignedBigInteger('subtotal_minor')->default(0);
            $table->unsignedBigInteger('discount_minor')->default(0);
            $table->unsignedSmallInteger('tax_rate_bps')->default(0);
            $table->unsignedBigInteger('tax_amount_minor')->default(0);
            $table->unsignedBigInteger('total_minor')->default(0);

            // Optional first instalment. Null means the whole total is due at
            // once; a value means the invoice collects in two stages.
            $table->unsignedBigInteger('deposit_minor')->nullable();

            $table->date('valid_until')->nullable();
            $table->text('terms')->nullable();
            $table->text('notes')->nullable();
            $table->text('internal_notes')->nullable();

            // Bumped every time a sent quotation is pulled back for revision,
            // so the customer can tell one offer from another.
            $table->unsignedSmallInteger('revision')->default(1);

            $table->timestamp('sent_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->string('decline_reason', 500)->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason', 255)->nullable();

            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['status', 'valid_until']);
            $table->index('customer_id');
        });

        Schema::create('quotation_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quotation_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('description', 255);
            $table->string('unit_label', 24)->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedBigInteger('unit_price_minor');

            // Stored rather than derived so the document is auditable exactly
            // as it was rendered, and recomputed on every save.
            $table->unsignedBigInteger('line_total_minor');
            $table->timestamps();

            $table->index(['quotation_id', 'sort_order']);
        });

        Schema::create('invoices', function (Blueprint $table): void {
            $table->id();
            $table->string('number', 32)->unique();
            $table->char('tracking_token', 64)->unique();

            // One invoice per quotation, ever. A double conversion is impossible
            // rather than merely unlikely.
            $table->foreignId('quotation_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('status', 24)->default(InvoiceStatus::Draft->value);

            $table->string('contact_name', 180);
            $table->string('contact_email', 254);
            $table->string('contact_phone', 40);
            $table->string('company_name', 180)->nullable();

            $table->string('title', 180);
            $table->char('currency', 3);

            $table->unsignedBigInteger('subtotal_minor')->default(0);
            $table->unsignedBigInteger('discount_minor')->default(0);
            $table->unsignedSmallInteger('tax_rate_bps')->default(0);
            $table->unsignedBigInteger('tax_amount_minor')->default(0);
            $table->unsignedBigInteger('total_minor')->default(0);
            $table->unsignedBigInteger('deposit_minor')->nullable();

            $table->date('issued_on')->nullable();
            $table->date('due_on')->nullable();
            $table->text('terms')->nullable();
            $table->text('notes')->nullable();
            $table->text('internal_notes')->nullable();

            $table->timestamp('issued_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->string('closure_reason', 255)->nullable();

            $table->timestamps();

            $table->index(['status', 'due_on']);
            $table->index(['status', 'created_at']);
            $table->index('customer_id');
        });

        Schema::create('invoice_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('description', 255);
            $table->string('unit_label', 24)->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedBigInteger('unit_price_minor');
            $table->unsignedBigInteger('line_total_minor');
            $table->timestamps();

            $table->index(['invoice_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('quotation_items');
        Schema::dropIfExists('quotations');
        Schema::dropIfExists('quotation_requests');
        Schema::dropIfExists('number_sequences');
    }
};
