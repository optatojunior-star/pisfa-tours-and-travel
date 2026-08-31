<?php

use App\Enums\AirportTransferBookingStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('airports', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 8)->unique();
            $table->string('name', 180);
            $table->string('city', 120);
            $table->char('country_code', 2);
            $table->string('timezone', 64);
            $table->text('terminal_information')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_active', 'sort_order'], 'airports_active_sort_index');
            $table->index(['country_code', 'city'], 'airports_country_city_index');
        });

        Schema::create('airport_transfer_locations', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 200)->unique();
            $table->string('name', 180);
            $table->string('region', 120);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_active', 'sort_order'], 'airport_transfer_locations_active_sort_index');
            $table->index(['region', 'name'], 'airport_transfer_locations_region_name_index');
        });

        Schema::create('airport_transfer_rates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('airport_id')->constrained('airports')->restrictOnDelete();
            $table->foreignId('airport_transfer_location_id')
                ->constrained('airport_transfer_locations')
                ->restrictOnDelete();
            $table->string('transfer_type', 24);
            $table->string('vehicle_type', 40);
            $table->char('currency', 3);
            $table->unsignedSmallInteger('passenger_capacity');
            $table->unsignedSmallInteger('luggage_capacity');
            $table->unsignedBigInteger('amount_minor');
            $table->unsignedSmallInteger('estimated_duration_minutes');
            $table->dateTime('effective_from');
            $table->dateTime('effective_until')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(
                [
                    'airport_id',
                    'airport_transfer_location_id',
                    'transfer_type',
                    'vehicle_type',
                    'currency',
                    'effective_from',
                ],
                'airport_transfer_rates_version_unique',
            );
            $table->index(
                ['airport_id', 'airport_transfer_location_id', 'transfer_type', 'currency', 'is_active', 'effective_from'],
                'airport_transfer_rates_lookup_index',
            );
            $table->index(
                ['vehicle_type', 'passenger_capacity', 'luggage_capacity'],
                'airport_transfer_rates_capacity_index',
            );
        });

        Schema::create('airport_transfer_bookings', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 40)->unique();
            $table->foreignId('customer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('airport_id')->constrained('airports')->restrictOnDelete();
            $table->foreignId('airport_transfer_location_id')
                ->constrained('airport_transfer_locations')
                ->restrictOnDelete();
            $table->foreignId('airport_transfer_rate_id')
                ->constrained('airport_transfer_rates')
                ->restrictOnDelete();
            $table->char('idempotency_owner_hash', 64);
            $table->string('idempotency_key', 100);
            $table->char('request_fingerprint', 64);
            $table->string('status', 24)->default(AirportTransferBookingStatus::Pending->value);
            $table->string('transfer_type', 24);
            $table->string('airport_code_snapshot', 8);
            $table->string('airport_name_snapshot', 180);
            $table->string('location_name_snapshot', 180);
            $table->string('vehicle_type_snapshot', 40);
            $table->unsignedSmallInteger('passenger_capacity_snapshot');
            $table->unsignedSmallInteger('luggage_capacity_snapshot');
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->unsignedSmallInteger('estimated_duration_minutes');
            $table->dateTime('service_starts_at');
            $table->dateTime('service_ends_at');
            $table->dateTime('cancellation_cutoff_at');
            $table->dateTime('request_expires_at');
            $table->text('flight_number')->nullable();
            $table->dateTime('flight_scheduled_at');
            $table->unsignedSmallInteger('passenger_count');
            $table->unsignedSmallInteger('luggage_count')->default(0);
            $table->text('service_address');
            $table->string('contact_name', 180);
            $table->string('contact_email', 255);
            $table->string('contact_phone', 40);
            $table->text('special_requests')->nullable();
            $table->text('internal_notes')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->foreignId('cancelled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();
            $table->foreignId('assigned_driver_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('confirmed_at')->nullable();
            $table->dateTime('in_progress_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->dateTime('declined_at')->nullable();
            $table->dateTime('expired_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['idempotency_owner_hash', 'idempotency_key'],
                'airport_transfer_bookings_idempotency_unique',
            );
            $table->index(
                ['customer_id', 'status', 'created_at'],
                'airport_transfer_bookings_customer_status_index',
            );
            $table->index(
                ['airport_id', 'airport_transfer_location_id', 'transfer_type', 'service_starts_at'],
                'airport_transfer_bookings_catalogue_index',
            );
            $table->index(
                ['status', 'request_expires_at'],
                'airport_transfer_bookings_expiry_index',
            );
            $table->index(
                ['assigned_vehicle_id', 'status', 'service_starts_at', 'service_ends_at'],
                'airport_transfer_bookings_vehicle_overlap_index',
            );
            $table->index(
                ['assigned_driver_user_id', 'status', 'service_starts_at', 'service_ends_at'],
                'airport_transfer_bookings_driver_overlap_index',
            );
            $table->index('flight_scheduled_at', 'airport_transfer_bookings_flight_schedule_index');
        });

        Schema::create('airport_transfer_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('airport_transfer_booking_id')
                ->constrained('airport_transfer_bookings')
                ->cascadeOnDelete();
            $table->foreignId('vehicle_id')->constrained('vehicles')->restrictOnDelete();
            $table->foreignId('driver_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('assigned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->dateTime('assigned_at');
            $table->dateTime('unassigned_at')->nullable();
            $table->foreignId('unassigned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('unassignment_reason')->nullable();
            $table->timestamps();

            $table->index(
                ['airport_transfer_booking_id', 'unassigned_at', 'assigned_at'],
                'airport_transfer_assignments_booking_index',
            );
            $table->index(
                ['driver_user_id', 'unassigned_at', 'starts_at', 'ends_at'],
                'airport_transfer_assignments_driver_overlap_index',
            );
            $table->index(
                ['vehicle_id', 'unassigned_at', 'starts_at', 'ends_at'],
                'airport_transfer_assignments_vehicle_overlap_index',
            );
        });

        Schema::create('airport_transfer_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('airport_transfer_booking_id')
                ->constrained('airport_transfer_bookings')
                ->cascadeOnDelete();
            $table->string('event_type', 48);
            $table->json('payload')->nullable();
            $table->dateTime('processed_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['airport_transfer_booking_id', 'event_type'],
                'airport_transfer_events_booking_type_unique',
            );
            $table->index(
                ['event_type', 'processed_at'],
                'airport_transfer_events_processing_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('airport_transfer_events');
        Schema::dropIfExists('airport_transfer_assignments');
        Schema::dropIfExists('airport_transfer_bookings');
        Schema::dropIfExists('airport_transfer_rates');
        Schema::dropIfExists('airport_transfer_locations');
        Schema::dropIfExists('airports');
    }
};
