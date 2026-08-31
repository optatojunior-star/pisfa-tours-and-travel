<?php

namespace App\Models;

use App\Enums\DocumentCategory;
use App\Enums\ReviewStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property ReviewStatus $status
 * @property int $rating
 * @property string $title
 * @property string $body
 * @property string|null $reply_body
 * @property string|null $moderation_note
 * @property CarbonImmutable|null $published_at
 * @property CarbonImmutable|null $replied_at
 * @property CarbonImmutable|null $moderated_at
 * @property User|null $customer
 */
class Review extends Model
{
    use HasFactory;

    // Soft deleted so moderation history survives a removal and the summary
    // can be recomputed correctly.
    use SoftDeletes;

    protected $fillable = [
        'reference',
        'customer_id',
        'booking_type',
        'booking_id',
        'reviewable_type',
        'reviewable_id',
        'rating',
        'title',
        'body',
        'status',
        'reply_body',
        'replied_by_user_id',
        'replied_at',
        'moderated_by_user_id',
        'moderated_at',
        'moderation_note',
        'published_at',
    ];

    /**
     * The moderation note explains a rejection to the author and to staff. It
     * is never part of a public payload.
     */
    protected $hidden = ['moderation_note'];

    protected function casts(): array
    {
        return [
            'status' => ReviewStatus::class,
            'rating' => 'integer',
            'replied_at' => 'immutable_datetime',
            'moderated_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'reference';
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function booking(): MorphTo
    {
        return $this->morphTo('booking');
    }

    public function reviewable(): MorphTo
    {
        return $this->morphTo('reviewable');
    }

    public function repliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'replied_by_user_id');
    }

    public function moderatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moderated_by_user_id');
    }

    public function moderationEvents(): HasMany
    {
        return $this->hasMany(ReviewModerationEvent::class)->latest('id');
    }

    /** Photographs, stored through the F27 document system. */
    public function media(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable')
            ->where('category', DocumentCategory::ReviewMedia->value);
    }

    /**
     * The only scope a public surface may use. Anything that renders reviews to
     * visitors must go through this.
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->whereIn('status', ReviewStatus::publicValues());
    }

    public function scopeAwaitingModeration(Builder $query): Builder
    {
        return $query->where('status', ReviewStatus::Pending->value);
    }

    public function scopeForCustomer(Builder $query, User|int $customer): Builder
    {
        return $query->where('customer_id', $customer instanceof User ? $customer->getKey() : $customer);
    }

    public function scopeForSubject(Builder $query, Model $subject): Builder
    {
        return $query
            ->where('reviewable_type', $subject->getMorphClass())
            ->where('reviewable_id', $subject->getKey());
    }

    public function isPublic(): bool
    {
        return $this->status->isPublic();
    }

    public function hasReply(): bool
    {
        return filled($this->reply_body);
    }

    public function canTransitionTo(ReviewStatus $next): bool
    {
        return $this->status->canTransitionTo($next);
    }

    /** Author display name, trimmed for public use. */
    public function authorName(): string
    {
        $name = trim((string) ($this->customer->name ?? 'PISFA customer'));
        $parts = preg_split('/\s+/', $name) ?: [$name];

        // First name plus a surname initial, so a public page does not publish
        // a customer's full name without them choosing to.
        if (count($parts) < 2) {
            return $parts[0];
        }

        return $parts[0].' '.mb_strtoupper(mb_substr((string) end($parts), 0, 1)).'.';
    }
}
