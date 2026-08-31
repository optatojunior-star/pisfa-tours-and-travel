<?php

use App\Enums\TourBookingStatus;
use App\Enums\TourDepartureStatus;
use App\Enums\TourPackageStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tour_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('slug', 140)->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });

        Schema::create('tour_packages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tour_category_id')
                ->constrained('tour_categories')
                ->restrictOnDelete();
            $table->string('name', 180);
            $table->string('slug', 200)->unique();
            $table->string('destination', 180)->nullable();
            $table->string('summary', 500);
            $table->longText('description');
            $table->string('status', 24)->default(TourPackageStatus::Draft->value);
            $table->dateTime('published_at')->nullable();
            $table->boolean('is_featured')->default(false);
            $table->unsignedSmallInteger('duration_days');
            $table->unsignedBigInteger('base_price_minor');
            $table->char('currency', 3);
            $table->unsignedSmallInteger('min_travelers')->default(1);
            $table->unsignedSmallInteger('max_travelers');
            $table->unsignedSmallInteger('cancellation_cutoff_hours')->default(24);
            $table->foreignId('created_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignId('updated_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'published_at']);
            $table->index(['tour_category_id', 'status']);
            $table->index(['status', 'is_featured', 'published_at']);
        });

        Schema::create('tour_package_media', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tour_package_id')
                ->constrained('tour_packages')
                ->cascadeOnDelete();
            $table->string('url', 2048);
            $table->string('alt_text', 255)->nullable();
            $table->string('caption', 500)->nullable();
            $table->boolean('is_cover')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['tour_package_id', 'sort_order']);
            $table->index(['tour_package_id', 'is_cover']);
        });

        Schema::create('tour_itinerary_days', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tour_package_id')
                ->constrained('tour_packages')
                ->cascadeOnDelete();
            $table->unsignedSmallInteger('day_number');
            $table->string('title', 180);
            $table->text('description')->nullable();
            $table->json('activities')->nullable();
            $table->string('meals', 255)->nullable();
            $table->string('overnight_location', 255)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['tour_package_id', 'day_number']);
            $table->index(['tour_package_id', 'sort_order']);
        });

        Schema::create('tour_package_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tour_package_id')
                ->constrained('tour_packages')
                ->cascadeOnDelete();
            $table->string('item_type', 24);
            $table->string('content', 500);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['tour_package_id', 'item_type', 'sort_order']);
        });

        Schema::create('tour_departures', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tour_package_id')
                ->constrained('tour_packages')
                ->restrictOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->dateTime('cancellation_cutoff_at');
            $table->unsignedSmallInteger('capacity');
            $table->unsignedBigInteger('price_override_minor')->nullable();
            $table->char('currency', 3)->nullable();
            $table->string('status', 24)->default(TourDepartureStatus::Scheduled->value);
            $table->string('meeting_point', 500)->nullable();
            $table->text('customer_notes')->nullable();
            $table->text('internal_notes')->nullable();
            $table->timestamps();

            $table->unique(['tour_package_id', 'starts_at']);
            $table->index(['status', 'starts_at']);
            $table->index(['tour_package_id', 'status', 'starts_at']);
        });

        Schema::create('tour_bookings', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 40)->unique();
            $table->foreignId('customer_id')
                ->constrained('users')
                ->restrictOnDelete();
            $table->foreignId('tour_package_id')
                ->constrained('tour_packages')
                ->restrictOnDelete();
            $table->foreignId('tour_departure_id')
                ->constrained('tour_departures')
                ->restrictOnDelete();
            $table->string('idempotency_key', 100);
            $table->string('status', 24)->default(TourBookingStatus::Pending->value);
            $table->unsignedSmallInteger('traveler_count');
            $table->string('package_name_snapshot', 180);
            $table->string('destination_snapshot', 180)->nullable();
            $table->dateTime('departure_starts_at_snapshot');
            $table->dateTime('departure_ends_at_snapshot');
            $table->dateTime('cancellation_cutoff_at_snapshot');
            $table->unsignedBigInteger('unit_price_minor');
            $table->unsignedBigInteger('subtotal_minor');
            $table->unsignedBigInteger('total_minor');
            $table->char('currency', 3);
            $table->string('contact_name', 180);
            $table->string('contact_email', 255);
            $table->string('contact_phone', 40);
            $table->text('special_requests')->nullable();
            $table->text('internal_notes')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->foreignId('cancelled_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->dateTime('confirmed_at')->nullable();
            $table->dateTime('in_progress_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->foreignId('assigned_driver_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamps();

            $table->unique(['customer_id', 'idempotency_key']);
            $table->index(['customer_id', 'status', 'created_at']);
            $table->index(['tour_departure_id', 'status']);
            $table->index(['tour_package_id', 'status']);
            $table->index(['assigned_driver_user_id', 'status']);
        });

        Schema::create('tour_travelers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tour_booking_id')
                ->constrained('tour_bookings')
                ->cascadeOnDelete();
            $table->string('full_name', 180);
            $table->string('traveler_type', 24);
            $table->date('date_of_birth')->nullable();
            $table->string('nationality', 100)->nullable();
            $table->text('dietary_notes')->nullable();
            $table->text('accessibility_notes')->nullable();
            $table->boolean('is_lead')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['tour_booking_id', 'sort_order']);
            $table->index(['tour_booking_id', 'is_lead']);
        });

        Schema::create('tour_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tour_booking_id')
                ->constrained('tour_bookings')
                ->cascadeOnDelete();
            $table->foreignId('driver_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignId('assigned_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->dateTime('assigned_at');
            $table->dateTime('unassigned_at')->nullable();
            $table->foreignId('unassigned_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->text('unassignment_reason')->nullable();
            $table->timestamps();

            $table->index(['tour_booking_id', 'assigned_at']);
            $table->index(['driver_user_id', 'unassigned_at', 'starts_at', 'ends_at'], 'tour_assignments_driver_overlap_index');
        });

        Schema::create('tour_booking_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tour_booking_id')
                ->constrained('tour_bookings')
                ->cascadeOnDelete();
            $table->string('event_type', 48);
            $table->json('payload')->nullable();
            $table->dateTime('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['tour_booking_id', 'event_type']);
            $table->index(['event_type', 'processed_at']);
        });

        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->dateTime('read_at')->nullable();
            $table->timestamps();

            $table->index(['notifiable_type', 'notifiable_id', 'read_at'], 'notifications_notifiable_unread_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('tour_booking_events');
        Schema::dropIfExists('tour_assignments');
        Schema::dropIfExists('tour_travelers');
        Schema::dropIfExists('tour_bookings');
        Schema::dropIfExists('tour_departures');
        Schema::dropIfExists('tour_package_items');
        Schema::dropIfExists('tour_itinerary_days');
        Schema::dropIfExists('tour_package_media');
        Schema::dropIfExists('tour_packages');
        Schema::dropIfExists('tour_categories');
    }
};
