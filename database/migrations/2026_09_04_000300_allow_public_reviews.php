<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reviews from people who are not signed in.
 *
 * Until now a review could only exist against a completed booking made by a
 * registered customer, which is a good rule for a verified review and a
 * complete answer to "can people leave us a review on the website" of no. Most
 * of PISFA's customers arrange a trip over WhatsApp and never create an
 * account; a review system they cannot reach is one nobody writes in.
 *
 * So three columns become nullable and two are added. Nothing is relaxed about
 * what gets published: a guest review lands Pending exactly as a customer's
 * does, and a moderator is still the only way anything reaches the public page.
 *
 * The nullable booking columns keep their unique index. MySQL and SQLite both
 * allow repeated NULLs in a unique index, so guest reviews do not collide with
 * each other while a booking can still be reviewed only once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table): void {
            // A guest has no account, and inventing a placeholder user to point
            // at would be worse than a null: it would look like a real customer
            // in every report that counts them.
            $table->foreignId('customer_id')->nullable()->change();
            $table->string('booking_type')->nullable()->change();
            $table->unsignedBigInteger('booking_id')->nullable()->change();

            // Who wrote it, when there is no account to ask. The email is never
            // shown publicly — it exists so a moderator can reply to somebody
            // who reported a problem, and so obvious repeat spam is visible.
            $table->string('guest_name', 120)->nullable()->after('customer_id');
            $table->string('guest_email', 190)->nullable()->after('guest_name');

            $table->index(['guest_email', 'created_at'], 'reviews_guest_index');
        });
    }

    public function down(): void
    {
        // Guest rows cannot survive a return to the old shape: they have no
        // customer and no booking, which is precisely what the old columns
        // required. They are removed rather than left to break the constraint.
        DB::table('reviews')->whereNull('customer_id')->delete();

        Schema::table('reviews', function (Blueprint $table): void {
            $table->dropIndex('reviews_guest_index');
            $table->dropColumn(['guest_name', 'guest_email']);
        });

        Schema::table('reviews', function (Blueprint $table): void {
            $table->foreignId('customer_id')->nullable(false)->change();
            $table->string('booking_type')->nullable(false)->change();
            $table->unsignedBigInteger('booking_id')->nullable(false)->change();
        });
    }
};
