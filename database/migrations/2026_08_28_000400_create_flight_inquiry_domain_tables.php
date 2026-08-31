<?php

use App\Enums\FlightInquiryStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flight_inquiries', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 40)->unique();
            $table->foreignId('customer_id')->nullable()->constrained('users')->nullOnDelete();

            // Guest submissions are deduplicated on a keyed hash of the contact
            // identity rather than a nullable user id, so a repeated form post
            // cannot create a second inquiry.
            $table->char('idempotency_owner_hash', 64);
            $table->uuid('idempotency_key');
            $table->char('request_fingerprint', 64);

            $table->string('status', 24)->default(FlightInquiryStatus::New->value);
            $table->string('scope', 24);
            $table->string('trip_type', 16);
            $table->string('travel_class', 24);

            $table->string('origin', 120);
            $table->string('destination', 120);
            $table->date('outbound_on');
            $table->date('return_on')->nullable();
            $table->unsignedSmallInteger('passenger_count');

            $table->string('contact_name', 180);
            $table->string('contact_email', 254);
            $table->string('contact_phone', 40);
            $table->text('notes')->nullable();

            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();
            $table->text('resolution_reason')->nullable();

            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('first_contacted_at')->nullable();
            $table->timestamp('booked_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('reopened_at')->nullable();
            $table->unsignedSmallInteger('reopen_count')->default(0);

            $table->timestamps();

            $table->unique(
                ['idempotency_owner_hash', 'idempotency_key'],
                'flight_inquiries_idempotency_unique',
            );
            $table->index(['status', 'outbound_on'], 'flight_inquiries_status_departure_index');
            $table->index(['scope', 'status'], 'flight_inquiries_scope_status_index');
            $table->index(['assigned_to_user_id', 'status'], 'flight_inquiries_owner_status_index');
            $table->index(['customer_id', 'created_at'], 'flight_inquiries_customer_index');
            $table->index('contact_email', 'flight_inquiries_contact_email_index');
        });

        Schema::create('flight_inquiry_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('flight_inquiry_id')->constrained('flight_inquiries')->cascadeOnDelete();
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('entry_type', 32);
            $table->text('body');
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['flight_inquiry_id', 'created_at'], 'flight_inquiry_entries_timeline_index');
            $table->index(['flight_inquiry_id', 'entry_type'], 'flight_inquiry_entries_type_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flight_inquiry_entries');
        Schema::dropIfExists('flight_inquiries');
    }
};
