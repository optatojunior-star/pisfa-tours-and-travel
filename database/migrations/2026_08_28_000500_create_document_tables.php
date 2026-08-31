<?php

use App\Enums\DocumentVisibility;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table): void {
            $table->id();

            // Polymorphic owner: a booking, import order, property, post,
            // review, expense, payroll run, or any future subject.
            $table->string('documentable_type');
            $table->unsignedBigInteger('documentable_id');

            $table->string('category', 40);
            $table->string('visibility', 16)->default(DocumentVisibility::Private->value);

            $table->string('disk', 40);
            $table->string('path', 500);
            $table->string('original_name', 255)->nullable();
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size_bytes');
            $table->char('content_sha256', 64);
            $table->unsignedInteger('image_width')->nullable();
            $table->unsignedInteger('image_height')->nullable();

            // Versioned categories keep prior revisions. Exactly one revision
            // per (owner, category) may be current.
            $table->unsignedInteger('version')->default(1);
            $table->boolean('is_current')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_generated')->default(false);
            $table->json('metadata')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(
                ['documentable_type', 'documentable_id', 'category', 'is_current'],
                'documents_owner_category_index',
            );
            $table->index(['category', 'created_at'], 'documents_category_created_index');
            $table->index('content_sha256', 'documents_checksum_index');

            // Two rows cannot both claim to be the current revision of the same
            // category for the same owner. Enforced by the database, not by
            // application discipline alone.
            $table->unique(
                ['documentable_type', 'documentable_id', 'category', 'version'],
                'documents_owner_category_version_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
