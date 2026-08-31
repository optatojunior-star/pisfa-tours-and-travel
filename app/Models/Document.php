<?php

namespace App\Models;

use App\Enums\DocumentCategory;
use App\Enums\DocumentVisibility;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * Cast-backed attributes declared for static analysis. Larastan reads the
 * database schema, which stores these as strings, so without these the enum
 * and array casts are invisible to it.
 *
 * @property int $id
 * @property string $documentable_type
 * @property int $documentable_id
 * @property DocumentCategory $category
 * @property DocumentVisibility $visibility
 * @property string $disk
 * @property string $path
 * @property string|null $original_name
 * @property string $mime_type
 * @property int $size_bytes
 * @property string $content_sha256
 * @property int|null $image_width
 * @property int|null $image_height
 * @property int $version
 * @property bool $is_current
 * @property int $sort_order
 * @property int|null $uploaded_by_user_id
 * @property bool $is_generated
 * @property array<string, mixed>|null $metadata
 * @property CarbonImmutable|null $expires_at
 */
class Document extends Model
{
    use HasFactory;

    // Soft deleted so a removed contract or receipt still has an audit trail;
    // the underlying file is purged separately by an authorized action.
    use SoftDeletes;

    protected $fillable = [
        'documentable_type',
        'documentable_id',
        'category',
        'visibility',
        'disk',
        'path',
        'original_name',
        'mime_type',
        'size_bytes',
        'content_sha256',
        'image_width',
        'image_height',
        'version',
        'is_current',
        'sort_order',
        'uploaded_by_user_id',
        'is_generated',
        'metadata',
        'expires_at',
        'expiry_alert_sent_at',
    ];

    /**
     * The storage location and content hash are internal. Exposing them would
     * let a client probe the filesystem layout or confirm file contents.
     */
    protected $hidden = [
        'disk',
        'path',
        'content_sha256',
    ];

    protected function casts(): array
    {
        return [
            'category' => DocumentCategory::class,
            'visibility' => DocumentVisibility::class,
            'size_bytes' => 'integer',
            'image_width' => 'integer',
            'image_height' => 'integer',
            'version' => 'integer',
            'is_current' => 'boolean',
            'sort_order' => 'integer',
            'is_generated' => 'boolean',
            'metadata' => 'array',
            'expires_at' => 'immutable_date',
            'expiry_alert_sent_at' => 'immutable_datetime',
        ];
    }

    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->where('is_current', true);
    }

    public function scopeOfCategory(Builder $query, DocumentCategory $category): Builder
    {
        return $query->where('category', $category->value);
    }

    public function scopePrivate(Builder $query): Builder
    {
        return $query->where('visibility', DocumentVisibility::Private->value);
    }

    public function scopePublic(Builder $query): Builder
    {
        return $query->where('visibility', DocumentVisibility::Public->value);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderByDesc('version')->orderBy('id');
    }

    public function isPrivate(): bool
    {
        return $this->visibility === DocumentVisibility::Private;
    }

    public function isImage(): bool
    {
        return in_array($this->mime_type, (array) config('documents.images.mimes', []), true);
    }

    public function exists(): bool
    {
        return Storage::disk($this->disk)->exists($this->path);
    }

    /**
     * Direct URL for public catalogue media only. A private document has no
     * URL by design — call temporarySignedUrl() or route the caller through
     * the authorized download controller.
     */
    public function url(): ?string
    {
        if (! $this->visibility->allowsDirectUrl()) {
            return null;
        }

        return Storage::disk($this->disk)->url($this->path);
    }

    /**
     * Short-lived link for a recipient with no session, such as a guest
     * quotation. Authenticated users should use the authorized route instead.
     */
    public function temporarySignedUrl(?int $minutes = null): string
    {
        $minutes ??= (int) config('documents.signed_url_minutes', 15);

        return URL::temporarySignedRoute(
            'documents.signed',
            now()->addMinutes($minutes),
            ['document' => $this->getKey()],
        );
    }

    public function humanSize(): string
    {
        $bytes = (int) $this->size_bytes;

        if ($bytes < 1024) {
            return $bytes.' B';
        }

        if ($bytes < 1048576) {
            return round($bytes / 1024, 1).' KB';
        }

        return round($bytes / 1048576, 1).' MB';
    }

    /**
     * A stable, safe filename for the browser's Save dialog. The stored name is
     * random, and the original name is untrusted, so neither is used verbatim.
     */
    public function downloadName(): string
    {
        $extension = pathinfo($this->path, PATHINFO_EXTENSION);
        $slug = str($this->category->label())->slug();

        return $slug.'-'.$this->getKey().($extension === '' ? '' : '.'.$extension);
    }
}
