<?php

namespace App\Models;

use App\Enums\ReviewModerationAction;
use App\Enums\ReviewStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only moderation history. Never edited, so the record of who decided
 * what, and when, survives any later change to the review.
 *
 * @property ReviewModerationAction $action
 * @property ReviewStatus|null $from_status
 * @property ReviewStatus|null $to_status
 */
class ReviewModerationEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'review_id',
        'actor_user_id',
        'action',
        'from_status',
        'to_status',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'action' => ReviewModerationAction::class,
            'from_status' => ReviewStatus::class,
            'to_status' => ReviewStatus::class,
        ];
    }

    public function review(): BelongsTo
    {
        return $this->belongsTo(Review::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
