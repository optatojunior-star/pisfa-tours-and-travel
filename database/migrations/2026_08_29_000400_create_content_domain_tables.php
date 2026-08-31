<?php

use App\Enums\PostStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('post_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('slug', 140)->unique();
            $table->string('description', 500)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });

        Schema::create('posts', function (Blueprint $table): void {
            $table->id();

            // The slug is the public URL, so it is unique and immutable once a
            // post has been published — see SavePost.
            $table->string('slug', 200)->unique();
            $table->string('title', 200);
            $table->string('excerpt', 500);
            $table->longText('body');

            $table->foreignId('post_category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('status', 24)->default(PostStatus::Draft->value);

            /*
             * When the post becomes public. Set for both Scheduled and
             * Published, which is what lets one sweep promote the former by
             * comparing this against the clock.
             */
            $table->timestamp('published_at')->nullable();
            $table->timestamp('archived_at')->nullable();

            $table->boolean('is_featured')->default(false);

            // Editable SEO, which F01 recorded as a gap. Falls back to the
            // title and excerpt when blank.
            $table->string('meta_title', 200)->nullable();
            $table->string('meta_description', 300)->nullable();

            $table->unsignedInteger('reading_minutes')->default(1);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'published_at']);
            $table->index(['is_featured', 'published_at']);
            $table->index('post_category_id');
        });

        Schema::create('post_tag', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->string('tag', 60);
            $table->timestamps();

            // One of each tag per post, enforced by the database so a double
            // submit cannot duplicate one.
            $table->unique(['post_id', 'tag']);
            $table->index('tag');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_tag');
        Schema::dropIfExists('posts');
        Schema::dropIfExists('post_categories');
    }
};
