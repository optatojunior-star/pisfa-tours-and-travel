<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two small tables for things the site says about itself.
 *
 * `team_members` is the people on the about page. It is deliberately not the
 * users table: a guide whose face belongs on the website does not need a login,
 * and a super administrator does not belong on the website. Tying the two
 * together would mean creating an account to publish a photograph, which is
 * exactly the kind of shortcut that ends with a staff login nobody uses.
 *
 * `service_images` lets each service carry a picture of its own instead of the
 * shared line icon. There is one row per service key, and it exists only to be
 * something a Document can hang from — the file itself goes through the same
 * upload, inspection and audit path as every other image on the site.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_members', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('role_title', 120);
            $table->string('summary', 400)->nullable();
            $table->text('biography')->nullable();
            $table->string('email', 190)->nullable();
            $table->string('phone', 40)->nullable();

            // Published and ordered are separate: a profile can be written and
            // held back, and the running order is set by hand because seniority
            // is not alphabetical.
            $table->boolean('is_published')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_published', 'sort_order'], 'team_members_public_order_index');
        });

        Schema::create('service_images', function (Blueprint $table): void {
            $table->id();

            // The key from ServiceCatalogue::SERVICES. Unique, so a service can
            // never end up with two pictures and no rule about which wins.
            $table->string('service_key', 64)->unique();

            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_images');
        Schema::dropIfExists('team_members');
    }
};
