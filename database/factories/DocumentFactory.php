<?php

namespace Database\Factories;

use App\Enums\DocumentCategory;
use App\Enums\DocumentVisibility;
use App\Models\Document;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Document> */
class DocumentFactory extends Factory
{
    public function definition(): array
    {
        $category = DocumentCategory::IdentityDocument;

        return [
            'documentable_type' => null,
            'documentable_id' => null,
            'category' => $category,
            'visibility' => $category->visibility(),
            'disk' => $category->visibility()->disk(),
            'path' => 'documents/'.Str::lower(Str::random(8)).'/'.bin2hex(random_bytes(8)).'.pdf',
            'original_name' => 'document.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => fake()->numberBetween(1024, 512000),
            'content_sha256' => hash('sha256', Str::random(32)),
            'version' => 1,
            'is_current' => true,
            'sort_order' => 0,
            'uploaded_by_user_id' => null,
            'is_generated' => false,
        ];
    }

    public function ofCategory(DocumentCategory $category): static
    {
        return $this->state(fn (): array => [
            'category' => $category,
            'visibility' => $category->visibility(),
            'disk' => $category->visibility()->disk(),
        ]);
    }

    public function forOwner(object $owner): static
    {
        return $this->state(fn (): array => [
            'documentable_type' => $owner->getMorphClass(),
            'documentable_id' => $owner->getKey(),
        ]);
    }

    public function generated(): static
    {
        return $this->state(fn (): array => [
            'is_generated' => true,
            'original_name' => null,
        ]);
    }

    public function publicMedia(): static
    {
        return $this->state(fn (): array => [
            'category' => DocumentCategory::VehicleMedia,
            'visibility' => DocumentVisibility::Public,
            'disk' => DocumentVisibility::Public->disk(),
            'mime_type' => 'image/jpeg',
            'original_name' => 'photo.jpg',
        ]);
    }
}
