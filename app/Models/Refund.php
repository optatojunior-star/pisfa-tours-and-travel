<?php

namespace App\Models;

use App\Enums\RefundStatus;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property RefundStatus $status
 * @property int $amount_minor
 * @property array<string, mixed>|null $metadata
 * @property CarbonImmutable|null $completed_at
 */
class Refund extends Model
{
    use HasFactory;

    protected $fillable = [
        'reference',
        'payment_id',
        'requested_by_user_id',
        'status',
        'amount_minor',
        'currency',
        'reason',
        'provider_refund_id',
        'failure_reason',
        'metadata',
        'idempotency_key',
        'completed_at',
    ];

    protected $hidden = ['idempotency_key', 'provider_refund_id'];

    protected function casts(): array
    {
        return [
            'status' => RefundStatus::class,
            'amount_minor' => 'integer',
            'metadata' => 'array',
            'completed_at' => 'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'reference';
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', RefundStatus::Completed->value);
    }

    public function scopeClaiming(Builder $query): Builder
    {
        return $query->whereIn('status', RefundStatus::claimingValues());
    }

    public function canTransitionTo(RefundStatus $next): bool
    {
        return $this->status->canTransitionTo($next);
    }

    public function formattedAmount(): string
    {
        return Money::format($this->amount_minor, $this->currency);
    }
}
