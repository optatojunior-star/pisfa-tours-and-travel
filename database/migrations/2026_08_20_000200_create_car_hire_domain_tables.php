<?php

use App\Enums\CarHireBookingStatus;
use App\Enums\SelfDriveApplicationStatus;
use App\Enums\VehicleCatalogueStatus;
use App\Enums\VehicleOperationalStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicles', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 200)->unique();
            $table->string('registration_plate', 32)->unique();
            $table->string('make', 100);
            $table->string('model', 100);
            $table->unsignedSmallInteger('year');
            $table->string('color', 60);
            $table->string('condition', 40)->default('good');
            $table->string('vehicle_type', 40);
            $table->string('fuel_type', 40);
            $table->string('transmission', 40);
            $table->unsignedSmallInteger('seating_capacity');
            $table->unsignedSmallInteger('luggage_capacity')->default(0);
            $table->string('summary', 500);
            $table->text('description')->nullable();
            $table->string('catalogue_status', 24)->default(VehicleCatalogueStatus::Draft->value);
            $table->string('operational_status', 24)->default(VehicleOperationalStatus::Available->value);
            $table->dateTime('published_at')->nullable();
            $table->boolean('is_featured')->default(false);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['catalogue_status', 'operational_status', 'is_featured'], 'vehicles_public_status_index');
            $table->index(['catalogue_status', 'operational_status', 'vehicle_type', 'seating_capacity'], 'vehicles_public_filter_index');
        });

        Schema::create('vehicle_media', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vehicle_id')->constrained('vehicles')->cascadeOnDelete();
            $table->string('url', 2048);
            $table->string('alt_text', 255)->nullable();
            $table->string('caption', 500)->nullable();
            $table->boolean('is_cover')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['vehicle_id', 'sort_order']);
            $table->index(['vehicle_id', 'is_cover']);
        });

        Schema::create('vehicle_hire_rates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vehicle_id')->constrained('vehicles')->cascadeOnDelete();
            $table->char('currency', 3);
            $table->unsignedBigInteger('self_drive_daily_minor')->nullable();
            $table->unsignedBigInteger('with_driver_daily_minor')->nullable();
            $table->unsignedBigInteger('security_deposit_minor')->default(0);
            $table->dateTime('effective_from');
            $table->dateTime('effective_until')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['vehicle_id', 'currency', 'effective_from']);
            $table->index(['vehicle_id', 'currency', 'is_active', 'effective_from'], 'vehicle_hire_rates_lookup_index');
            $table->index(['currency', 'is_active', 'self_drive_daily_minor'], 'vehicle_hire_rates_self_drive_index');
            $table->index(['currency', 'is_active', 'with_driver_daily_minor'], 'vehicle_hire_rates_driver_index');
        });

        Schema::create('car_hire_bookings', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 40)->unique();
            $table->foreignId('customer_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('vehicle_id')->constrained('vehicles')->restrictOnDelete();
            $table->foreignId('vehicle_hire_rate_id')->constrained('vehicle_hire_rates')->restrictOnDelete();
            $table->string('idempotency_key', 100);
            $table->char('request_fingerprint', 64);
            $table->string('status', 24)->default(CarHireBookingStatus::Pending->value);
            $table->string('hire_mode', 24);
            $table->dateTime('pickup_at');
            $table->dateTime('return_at');
            $table->dateTime('cancellation_cutoff_at');
            $table->dateTime('hold_expires_at');
            $table->unsignedSmallInteger('billable_days');
            $table->string('vehicle_name_snapshot', 220);
            $table->string('registration_plate_snapshot', 32);
            $table->unsignedBigInteger('daily_rate_minor');
            $table->unsignedBigInteger('rental_subtotal_minor');
            $table->unsignedBigInteger('security_deposit_minor')->default(0);
            $table->unsignedBigInteger('total_minor');
            $table->char('currency', 3);
            $table->string('contact_name', 180);
            $table->string('contact_email', 255);
            $table->string('contact_phone', 40);
            $table->string('pickup_location', 500);
            $table->string('return_location', 500)->nullable();
            $table->text('special_requests')->nullable();
            $table->text('internal_notes')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->foreignId('cancelled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('confirmed_at')->nullable();
            $table->dateTime('in_progress_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->foreignId('assigned_driver_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['customer_id', 'idempotency_key']);
            $table->index(['customer_id', 'status', 'created_at']);
            $table->index(['vehicle_id', 'status', 'pickup_at', 'return_at'], 'car_hire_vehicle_overlap_index');
            $table->index(['status', 'hold_expires_at']);
            $table->index(['assigned_driver_user_id', 'status', 'pickup_at', 'return_at'], 'car_hire_driver_booking_index');
        });

        Schema::create('car_hire_self_drive_applications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('car_hire_booking_id')->unique()->constrained('car_hire_bookings')->cascadeOnDelete();
            $table->string('status', 24)->default(SelfDriveApplicationStatus::Draft->value);
            $table->text('national_id_number')->nullable();
            $table->text('driving_permit_number')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('driving_permit_issuing_country', 100)->nullable();
            $table->string('driving_permit_class', 40)->nullable();
            $table->date('driving_permit_issued_on')->nullable();
            $table->date('driving_permit_expires_on')->nullable();
            $table->dateTime('declaration_accepted_at')->nullable();
            $table->dateTime('submitted_at')->nullable();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->text('review_reason')->nullable();
            $table->text('internal_review_notes')->nullable();
            $table->dateTime('originals_verified_at')->nullable();
            // Explicitly named: the generated name would be
            // car_hire_self_drive_applications_originals_verified_by_user_id_foreign,
            // which is 70 characters and exceeds MySQL's 64-character identifier
            // limit. SQLite has no such limit, so this only fails on MySQL/MariaDB.
            $table->foreignId('originals_verified_by_user_id')->nullable()
                ->constrained(table: 'users', indexName: 'car_hire_sda_originals_verified_fk')
                ->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('car_hire_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('car_hire_booking_id')->constrained('car_hire_bookings')->cascadeOnDelete();
            $table->string('document_type', 40);
            $table->string('disk', 80);
            $table->string('path', 1024)->unique();
            $table->string('original_name', 255);
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size_bytes');
            $table->char('content_sha256', 64);
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['car_hire_booking_id', 'document_type']);
            $table->index(['car_hire_booking_id', 'document_type']);
        });

        Schema::create('car_hire_contracts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('car_hire_booking_id')->constrained('car_hire_bookings')->cascadeOnDelete();
            $table->string('contract_number', 48)->unique();
            $table->unsignedSmallInteger('version');
            $table->string('template_version', 32);
            $table->json('snapshot');
            $table->longText('terms_snapshot');
            $table->char('content_sha256', 64);
            $table->dateTime('issued_at');
            $table->dateTime('accepted_at')->nullable();
            $table->foreignId('accepted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('acceptance_ip', 45)->nullable();
            $table->string('acceptance_user_agent', 500)->nullable();
            $table->dateTime('voided_at')->nullable();
            $table->foreignId('voided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('void_reason')->nullable();
            $table->timestamps();

            $table->unique(['car_hire_booking_id', 'version']);
            $table->index(['accepted_at', 'issued_at']);
        });

        Schema::create('car_hire_driver_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('car_hire_booking_id')->constrained('car_hire_bookings')->cascadeOnDelete();
            $table->foreignId('driver_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->dateTime('assigned_at');
            $table->dateTime('unassigned_at')->nullable();
            $table->foreignId('unassigned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('unassignment_reason')->nullable();
            $table->timestamps();

            $table->index(['car_hire_booking_id', 'assigned_at'], 'car_hire_assignments_booking_index');
            $table->index(['driver_user_id', 'unassigned_at', 'starts_at', 'ends_at'], 'car_hire_assignments_driver_overlap_index');
        });

        Schema::create('car_hire_booking_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('car_hire_booking_id')->constrained('car_hire_bookings')->cascadeOnDelete();
            $table->string('event_type', 48);
            $table->json('payload')->nullable();
            $table->dateTime('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['car_hire_booking_id', 'event_type']);
            $table->index(['event_type', 'processed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('car_hire_booking_events');
        Schema::dropIfExists('car_hire_driver_assignments');
        Schema::dropIfExists('car_hire_contracts');
        Schema::dropIfExists('car_hire_documents');
        Schema::dropIfExists('car_hire_self_drive_applications');
        Schema::dropIfExists('car_hire_bookings');
        Schema::dropIfExists('vehicle_hire_rates');
        Schema::dropIfExists('vehicle_media');
        Schema::dropIfExists('vehicles');
    }
};
