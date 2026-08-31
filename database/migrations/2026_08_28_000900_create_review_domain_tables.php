<?php

use App\Enums\ReviewStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 40)->unique();
            $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();

            // The booking proves both eligibility and ownership: a customer can
            // only review something they actually bought and completed.
            $table->string('booking_type');
            $table->unsignedBigInteger('booking_id');

            // The subject shown publicly — a tour package, a vehicle, later a
            // property. Kept separate from the booking so many bookings of the
            // same tour aggregate onto one subject.
            $table->string('reviewable_type');
            $table->unsignedBigInteger('reviewable_id');

            $table->unsignedTinyInteger('rating');
            $table->string('title', 160);
            $table->text('body');
            $table->string('status', 24)->default(ReviewStatus::Pending->value);

            // Staff reply, shown beneath the review when published.
            $table->text('reply_body')->nullable();
            $table->foreignId('replied_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('replied_at')->nullable();

            $table->foreignId('moderated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('moderated_at')->nullable();

            // Shown to the author so a rejection is actionable; never public.
            $table->text('moderation_note')->nullable();

            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // One review per booking, ever. Enforced by the database so a
            // double submit cannot create two.
            $table->unique(['booking_type', 'booking_id'], 'reviews_booking_unique');

            $table->index(['reviewable_type', 'reviewable_id', 'status'], 'reviews_subject_status_index');
            $table->index(['status', 'created_at'], 'reviews_status_created_index');
            $table->index(['customer_id', 'created_at'], 'reviews_customer_index');
        });

        Schema::create('review_moderation_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('review_id')->constrained('reviews')->cascadeOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 24);
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24)->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['review_id', 'created_at'], 'review_moderation_history_index');
        });

        Schema::create('review_summaries', function (Blueprint $table): void {
            $table->id();
            $table->string('reviewable_type');
            $table->unsignedBigInteger('reviewable_id');

            // Sum and count rather than a stored average: the mean stays exact
            // and recomputable, and no float is ever persisted.
            $table->unsignedInteger('reviews_count')->default(0);
            $table->unsignedBigInteger('rating_sum')->default(0);

            // Distribution, for the star breakdown on a detail page.
            $table->unsignedInteger('rating_1_count')->default(0);
            $table->unsignedInteger('rating_2_count')->default(0);
            $table->unsignedInteger('rating_3_count')->default(0);
            $table->unsignedInteger('rating_4_count')->default(0);
            $table->unsignedInteger('rating_5_count')->default(0);

            $table->timestamps();

            $table->unique(['reviewable_type', 'reviewable_id'], 'review_summaries_subject_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_summaries');
        Schema::dropIfExists('review_moderation_events');
        Schema::dropIfExists('reviews');
    }
};
