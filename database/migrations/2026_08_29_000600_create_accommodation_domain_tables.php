<?php

use App\Enums\PropertyBookingStatus;
use App\Enums\PropertyStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('properties', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 200)->unique();
            $table->string('name', 200);
            $table->string('property_type', 24);

            $table->string('region', 120);
            $table->string('district', 120)->nullable();
            $table->string('address', 500)->nullable();

            $table->string('summary', 400);
            $table->text('description');
            $table->text('directions')->nullable();
            $table->text('internal_notes')->nullable();

            /*
             * Local wall-clock times, not timestamps: check-in is "from 14:00"
             * at the property, which is the same wall-clock time whatever the
             * date and whatever the reader's timezone.
             */
            $table->time('check_in_from')->default('14:00:00');
            $table->time('check_out_by')->default('10:00:00');

            /*
             * How long before arrival a guest may still cancel free of charge.
             * Held per property because a city hotel and a remote lodge do not
             * have the same terms.
             */
            $table->unsignedSmallInteger('cancellation_cutoff_hours')->default(48);

            $table->string('status', 24)->default(PropertyStatus::Draft->value);

            // Publication is a status *and* a date; the public scope needs both.
            $table->timestamp('published_at')->nullable();
            $table->boolean('is_featured')->default(false);

            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'published_at']);
            $table->index(['region', 'status']);
            $table->index(['is_featured', 'published_at']);
        });

        Schema::create('property_room_types', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->string('slug', 120);
            $table->string('name', 160);
            $table->text('description')->nullable();

            /*
             * The contended resource. Unlike a hire vehicle, which is one
             * physical thing, a room type is N interchangeable rooms — so
             * availability is a count against this number for every night of a
             * stay, not a yes/no overlap check.
             */
            $table->unsignedSmallInteger('quantity')->default(1);

            $table->unsignedTinyInteger('max_adults')->default(2);
            $table->unsignedTinyInteger('max_children')->default(0);
            $table->string('bed_configuration', 120)->nullable();
            $table->unsignedSmallInteger('size_sqm')->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(['property_id', 'slug']);
            $table->index(['property_id', 'is_active']);
        });

        Schema::create('property_room_rates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('property_room_type_id')->constrained()->cascadeOnDelete();

            $table->char('currency', 3);
            $table->unsignedBigInteger('nightly_rate_minor');

            /*
             * Seasonal pricing. High season in Uganda is not a discount on a
             * base rate, it is a different rate, so the whole stay has to fall
             * inside one rate window rather than being averaged across two.
             */
            $table->date('effective_from');
            $table->date('effective_until')->nullable();

            $table->unsignedTinyInteger('minimum_nights')->default(1);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['property_room_type_id', 'currency', 'effective_from'], 'room_rates_lookup_index');
        });

        Schema::create('property_bookings', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 40)->unique();

            $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->foreignId('property_room_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('property_room_rate_id')->nullable()->constrained()->nullOnDelete();

            $table->uuid('idempotency_key');
            $table->char('request_fingerprint', 64);

            $table->string('status', 24)->default(PropertyBookingStatus::Pending->value);

            /*
             * Dates, not timestamps. A stay is a set of nights: arriving on the
             * 10th and leaving on the 12th occupies the nights of the 10th and
             * 11th, and the room is free again on the 12th. Storing times here
             * would invite a timezone bug into what is a calendar question.
             */
            $table->date('check_in_date');
            $table->date('check_out_date');
            $table->unsignedSmallInteger('nights');

            // How many rooms of this type. The reason availability is arithmetic.
            $table->unsignedSmallInteger('rooms')->default(1);
            $table->unsignedSmallInteger('adults')->default(1);
            $table->unsignedSmallInteger('children')->default(0);

            // Snapshots, so a booking stays truthful after the property is edited.
            $table->string('property_name_snapshot', 200);
            $table->string('room_type_name_snapshot', 160);
            $table->time('check_in_from_snapshot');
            $table->time('check_out_by_snapshot');

            $table->unsignedBigInteger('nightly_rate_minor');
            $table->unsignedBigInteger('total_minor');
            $table->char('currency', 3);
            $table->unsignedBigInteger('paid_minor')->default(0);

            $table->string('contact_name', 180);
            $table->string('contact_email', 254);
            $table->string('contact_phone', 40);
            $table->text('special_requests')->nullable();
            $table->text('internal_notes')->nullable();

            $table->timestamp('hold_expires_at')->nullable();
            $table->timestamp('cancellation_cutoff_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('checked_in_at')->nullable();
            $table->timestamp('checked_out_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('closure_reason', 255)->nullable();

            $table->timestamps();

            // One customer replaying a submit is one booking.
            $table->unique(['customer_id', 'idempotency_key'], 'property_bookings_idem_unique');

            // The availability query: room type, then the night interval.
            $table->index(['property_room_type_id', 'status', 'check_in_date', 'check_out_date'], 'property_bookings_availability_index');
            $table->index(['property_id', 'status']);
            $table->index(['customer_id', 'status']);
            $table->index(['status', 'hold_expires_at']);
        });

        Schema::create('property_booking_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('property_booking_id')->constrained()->cascadeOnDelete();
            $table->string('event_type', 48);
            $table->json('payload')->nullable();
            $table->dateTime('processed_at')->nullable();
            $table->timestamps();

            /*
             * The marker that makes a repeated sweep harmless: one event of a
             * kind per booking, enforced by the database rather than by a
             * read-then-write check that two workers could both pass.
             */
            $table->unique(['property_booking_id', 'event_type']);
            $table->index(['event_type', 'processed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_booking_events');
        Schema::dropIfExists('property_bookings');
        Schema::dropIfExists('property_room_rates');
        Schema::dropIfExists('property_room_types');
        Schema::dropIfExists('properties');
    }
};
