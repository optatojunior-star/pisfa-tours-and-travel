<?php

use App\Enums\ListingStatus;
use App\Enums\SalesEnquiryStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_listings', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 40)->unique();
            $table->string('slug', 200)->unique();

            /*
             * A listing may be for a vehicle already in the hire fleet — the
             * usual way a retiring car reaches the showroom — or for stock
             * PISFA never hired out. Hence nullable, with its own snapshot
             * columns so a standalone listing is complete on its own.
             *
             * A fleet vehicle can be listed at most once at a time; the partial
             * uniqueness that would express is not portable, so the action
             * enforces it under a lock instead.
             */
            $table->foreignId('vehicle_id')->nullable()->constrained()->nullOnDelete();

            $table->string('status', 24)->default(ListingStatus::Draft->value);

            $table->string('title', 200);
            $table->string('make', 60);
            $table->string('model', 80);
            $table->unsignedSmallInteger('year');
            $table->string('body_type', 32)->nullable();
            $table->string('fuel_type', 24)->nullable();
            $table->string('transmission', 24)->nullable();
            $table->string('colour', 40)->nullable();
            $table->unsignedInteger('mileage_km')->nullable();
            $table->unsignedSmallInteger('seating_capacity')->nullable();
            $table->string('condition', 24)->nullable();

            $table->text('description');
            $table->text('internal_notes')->nullable();

            // Integer minor units, like every other money column in the system.
            $table->unsignedBigInteger('asking_price_minor');
            $table->char('currency', 3);

            // What it actually went for, which is rarely the asking price and
            // is the figure any sales report must use.
            $table->unsignedBigInteger('sold_price_minor')->nullable();

            $table->boolean('is_negotiable')->default(true);
            $table->boolean('is_featured')->default(false);

            $table->timestamp('listed_at')->nullable();
            $table->timestamp('reserved_at')->nullable();
            $table->timestamp('sold_at')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->string('closure_reason', 255)->nullable();

            $table->foreignId('sold_to_enquiry_id')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'listed_at']);
            $table->index(['status', 'asking_price_minor']);
            $table->index(['is_featured', 'listed_at']);
            $table->index('vehicle_id');
        });

        Schema::create('vehicle_sales_enquiries', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 40)->unique();

            $table->foreignId('vehicle_listing_id')->constrained()->cascadeOnDelete();

            // A guest may enquire, so this is nullable throughout — there is no
            // placeholder account.
            $table->foreignId('customer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('status', 24)->default(SalesEnquiryStatus::New->value);

            $table->string('contact_name', 180);
            $table->string('contact_email', 254);
            $table->string('contact_phone', 40);
            $table->text('message')->nullable();

            // What the buyer proposes. Recorded because a rejected offer is
            // still information about what the market thinks the car is worth.
            $table->unsignedBigInteger('offer_minor')->nullable();
            $table->char('offer_currency', 3)->nullable();

            $table->text('internal_notes')->nullable();
            $table->string('closure_reason', 255)->nullable();
            $table->timestamp('closed_at')->nullable();

            $table->char('idempotency_owner_hash', 64);
            $table->uuid('idempotency_key');

            $table->timestamps();

            // A repeated submit from the same browser is one enquiry.
            $table->unique(['idempotency_owner_hash', 'idempotency_key'], 'sales_enquiries_idem_unique');
            $table->index(['vehicle_listing_id', 'status']);
            $table->index(['status', 'created_at']);
        });

        Schema::table('vehicle_listings', function (Blueprint $table): void {
            $table->foreign('sold_to_enquiry_id')
                ->references('id')
                ->on('vehicle_sales_enquiries')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('vehicle_listings', function (Blueprint $table): void {
            $table->dropForeign(['sold_to_enquiry_id']);
        });

        Schema::dropIfExists('vehicle_sales_enquiries');
        Schema::dropIfExists('vehicle_listings');
    }
};
