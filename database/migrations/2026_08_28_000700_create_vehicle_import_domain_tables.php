<?php

use App\Enums\VehicleImportStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_import_orders', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 40)->unique();

            // Separate from the reference: the tracking token is the only
            // credential a guest has, so it is long, random, and never derived
            // from the reference or from anything guessable.
            $table->char('tracking_token', 64)->unique();

            $table->foreignId('customer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('status', 32)->default(VehicleImportStatus::Inquiry->value);

            // --- Requested specification -----------------------------------
            $table->string('make', 60);
            $table->string('model', 80);
            $table->unsignedSmallInteger('year_from');
            $table->unsignedSmallInteger('year_to');
            $table->string('body_type', 24);
            $table->string('fuel_type', 24);
            $table->string('transmission', 24);
            $table->string('drive_type', 16);
            $table->string('steering', 24);
            $table->unsignedInteger('engine_capacity_cc')->nullable();
            $table->char('origin_country', 2);
            $table->unsignedInteger('maximum_mileage_km')->nullable();
            $table->string('auction_grade', 16)->nullable();
            $table->string('preferred_colour', 40)->nullable();
            $table->unsignedSmallInteger('units')->default(1);
            $table->string('purpose', 40);
            $table->text('notes')->nullable();

            // Budget is what the customer proposes; the quote is what PISFA
            // charges. They are deliberately separate columns.
            $table->unsignedBigInteger('budget_minor');
            $table->char('budget_currency', 3);

            // --- Quotation --------------------------------------------------
            $table->unsignedBigInteger('total_price_minor')->nullable();
            $table->unsignedBigInteger('deposit_minor')->nullable();
            $table->char('quote_currency', 3)->nullable();
            $table->timestamp('quoted_at')->nullable();
            $table->timestamp('quote_expires_at')->nullable();
            $table->date('estimated_arrival_on')->nullable();

            // --- Sourced vehicle -------------------------------------------
            $table->string('chassis_number', 60)->nullable();
            $table->unsignedInteger('actual_mileage_km')->nullable();
            $table->string('actual_colour', 40)->nullable();
            $table->string('vessel_name', 120)->nullable();
            $table->string('bill_of_lading', 80)->nullable();

            // --- Contact (guest requests have no account) -------------------
            $table->string('contact_name', 180);
            $table->string('contact_email', 254);
            $table->string('contact_phone', 40);

            // Guest deduplication, mirroring the other public-intake domains.
            $table->char('idempotency_owner_hash', 64);
            $table->uuid('idempotency_key');

            $table->text('cancellation_reason')->nullable();
            $table->text('internal_notes')->nullable();

            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['idempotency_owner_hash', 'idempotency_key'],
                'vehicle_import_orders_idempotency_unique',
            );
            $table->index(['status', 'created_at'], 'vehicle_import_orders_status_index');
            $table->index(['customer_id', 'status'], 'vehicle_import_orders_customer_index');
            $table->index(['assigned_to_user_id', 'status'], 'vehicle_import_orders_owner_index');
            $table->index('contact_email', 'vehicle_import_orders_contact_index');
        });

        Schema::create('vehicle_import_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vehicle_import_order_id')
                ->constrained('vehicle_import_orders')
                ->cascadeOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 32);

            // Shown on the customer timeline. Internal-only entries stay in the
            // operations console.
            $table->boolean('is_customer_visible')->default(true);
            $table->string('summary', 255);
            $table->json('payload')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(
                ['vehicle_import_order_id', 'created_at'],
                'vehicle_import_events_timeline_index',
            );
            $table->index(['event_type', 'processed_at'], 'vehicle_import_events_type_index');
        });

        Schema::create('vehicle_import_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vehicle_import_order_id')
                ->constrained('vehicle_import_orders')
                ->cascadeOnDelete();
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();

            // A message is either from the customer or from PISFA. Internal
            // notes never reach the customer thread.
            $table->boolean('from_customer')->default(false);
            $table->boolean('is_internal')->default(false);
            $table->text('body');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(
                ['vehicle_import_order_id', 'created_at'],
                'vehicle_import_messages_thread_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_import_messages');
        Schema::dropIfExists('vehicle_import_events');
        Schema::dropIfExists('vehicle_import_orders');
    }
};
