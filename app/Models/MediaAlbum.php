<?php

namespace App\Models;

use App\Enums\DocumentCategory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Str;

/**
 * A folder in the image library.
 *
 * Exists to give a standalone upload an owner. Documents are polymorphic, and
 * making `documentable` nullable purely for library images would weaken every
 * query and policy that currently relies on it being present.
 *
 * @property string $name
 * @property string $slug
 * @property bool $is_default
 */
class MediaAlbum extends Model
{
    use HasFactory;

    protected $fillable = ['slug', 'name', 'description', 'is_default', 'default_marker', 'created_by_user_id'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** @return MorphMany<Document, $this> */
    public function images(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable')
            ->where('is_current', true)
            ->latest('id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * The album an upload lands in when none is chosen.
     *
     * Created on first use rather than seeded, so a fresh deployment does not
     * need to remember to make one.
     */
    public static function defaultAlbum(): self
    {
        $existing = static::query()->where('is_default', true)->first();

        if ($existing !== null) {
            return $existing;
        }

        return static::query()->create([
            'slug' => 'library',
            'name' => 'Image library',
            'description' => 'Images uploaded without a specific album.',
            'is_default' => true,
            // The marker is what the unique index constrains; NULL everywhere
            // else means any number of non-default albums.
            'default_marker' => 1,
        ]);
    }

    /**
     * The album that owns the home-page gallery.
     *
     * The gallery needed an owner for its uploads and an album is already
     * exactly that — a real record standing in for "no particular thing" — so
     * this reuses the mechanism rather than inventing a second one. The slug is
     * reserved: `firstOrCreate` on a unique column means two people uploading at
     * once get one album, not a duplicate-key error for whoever was second.
     */
    public static function homeGallery(): self
    {
        return static::query()->firstOrCreate(
            ['slug' => 'home-gallery'],
            [
                'name' => 'Home page gallery',
                'description' => 'Photographs shown in the sliding gallery on the home page.',
            ],
        );
    }

    /**
     * The gallery photographs, in the order they are shown.
     *
     * Oldest first, unlike `images()`: a gallery is a sequence somebody arranged
     * and `sort_order` records that arrangement, so newest-first would shuffle
     * it every time a picture was added.
     *
     * @return MorphMany<Document, $this>
     */
    public function galleryImages(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable')
            ->where('category', DocumentCategory::GalleryImage->value)
            ->where('is_current', true)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public static function makeSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'album';
        $slug = $base;
        $suffix = 1;

        while (static::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$suffix);
        }

        return $slug;
    }

    /** Images in this album that are shown on the public site. */
    public function publicImages(): MorphMany
    {
        return $this->images()->whereIn('category', [
            DocumentCategory::BlogMedia->value,
            DocumentCategory::VehicleMedia->value,
            DocumentCategory::PropertyMedia->value,
        ]);
    }
}
