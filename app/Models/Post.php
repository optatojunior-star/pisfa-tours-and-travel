<?php

namespace App\Models;

use App\Enums\DocumentCategory;
use App\Enums\PostStatus;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property PostStatus $status
 * @property string $slug
 * @property string $title
 * @property string $excerpt
 * @property string $body
 * @property bool $is_featured
 * @property int $reading_minutes
 * @property CarbonImmutable|null $published_at
 * @property PostCategory|null $category
 * @property User|null $author
 */
class Post extends Model
{
    use HasFactory;

    // Soft deleted so a removed post's URL can be recognised as gone rather
    // than silently reused by a later one.
    use SoftDeletes;

    protected $fillable = [
        'slug',
        'title',
        'excerpt',
        'body',
        'post_category_id',
        'author_user_id',
        'status',
        'published_at',
        'archived_at',
        'is_featured',
        'meta_title',
        'meta_description',
        'reading_minutes',
    ];

    protected function casts(): array
    {
        return [
            'status' => PostStatus::class,
            'published_at' => 'immutable_datetime',
            'archived_at' => 'immutable_datetime',
            'is_featured' => 'boolean',
            'reading_minutes' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(PostCategory::class, 'post_category_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    /** @return HasMany<PostTag, $this> */
    public function tags(): HasMany
    {
        return $this->hasMany(PostTag::class)->orderBy('tag');
    }

    /**
     * Cover image, filed through the F27 document system.
     *
     * @return MorphMany<Document, $this>
     */
    public function media(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable')
            ->where('category', DocumentCategory::BlogMedia->value);
    }

    /**
     * The only scope a public surface may use.
     *
     * Both halves matter: the status says it is meant to be public, and the
     * date says the moment has arrived. Checking one without the other is how a
     * scheduled post leaks early.
     */
    public function scopePublished(Builder $query, ?DateTimeInterface $at = null): Builder
    {
        return $query
            ->where('status', PostStatus::Published->value)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', $at ?? now());
    }

    public function scopeFeatured(Builder $query): Builder
    {
        return $query->where('is_featured', true);
    }

    /** Scheduled posts whose moment has arrived, for the publishing sweep. */
    public function scopeDueForPublication(Builder $query, ?DateTimeInterface $at = null): Builder
    {
        return $query
            ->where('status', PostStatus::Scheduled->value)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', $at ?? now());
    }

    public function scopeSearch(Builder $query, string $search): Builder
    {
        $search = trim($search);

        if ($search === '') {
            return $query;
        }

        return $query->where(function (Builder $nested) use ($search): void {
            $nested->where('title', 'like', '%'.$search.'%')
                ->orWhere('excerpt', 'like', '%'.$search.'%')
                ->orWhere('slug', 'like', '%'.$search.'%');
        });
    }

    public function scopeTagged(Builder $query, string $tag): Builder
    {
        return $query->whereHas('tags', fn (Builder $nested): Builder => $nested->where('tag', $tag));
    }

    public function isPublishedAt(?DateTimeInterface $at = null): bool
    {
        return $this->status->isPubliclyVisible()
            && $this->published_at !== null
            && ! $this->published_at->isAfter($at ?? now());
    }

    public function canTransitionTo(PostStatus $next): bool
    {
        return $this->status->canTransitionTo($next);
    }

    /** Editable SEO, falling back to the post's own words when blank. */
    public function metaTitle(): string
    {
        return filled($this->meta_title) ? (string) $this->meta_title : $this->title;
    }

    public function metaDescription(): string
    {
        return filled($this->meta_description) ? (string) $this->meta_description : $this->excerpt;
    }

    public function coverUrl(): ?string
    {
        $cover = $this->relationLoaded('media')
            ? $this->media->first()
            : $this->media()->first();

        return $cover?->url();
    }

    /** @return list<string> */
    public function tagList(): array
    {
        return $this->tags->pluck('tag')->all();
    }

    /**
     * A reading estimate at roughly 200 words a minute, floored at one.
     *
     * Computed on save rather than on render, so a listing of twenty posts does
     * not count twenty bodies.
     */
    public static function estimateReadingMinutes(string $body): int
    {
        $words = str_word_count(strip_tags($body));

        return max(1, (int) ceil($words / 200));
    }
}
