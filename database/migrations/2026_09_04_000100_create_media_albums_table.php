<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Albums for the image library.
 *
 * Documents are polymorphic — they attach to whatever owns them — so an image
 * uploaded on its own still needs an owner. An album is that owner: a real
 * record rather than a null documentable, which keeps every existing query,
 * policy and download path working unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_albums', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 120)->unique();
            $table->string('name', 120);
            $table->string('description', 500)->nullable();

            // The album new uploads land in when none is chosen. Exactly one row
            // may claim this, enforced below.
            $table->boolean('is_default')->default(false);

            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('name');
        });

        // A unique index over a nullable expression is the portable way to say
        // "at most one default": MySQL ignores NULLs in a unique index, so every
        // non-default row stores NULL here and only one row can hold the 1.
        Schema::table('media_albums', function (Blueprint $table): void {
            $table->unsignedTinyInteger('default_marker')->nullable();
            $table->unique('default_marker', 'media_albums_single_default');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_albums');
    }
};
