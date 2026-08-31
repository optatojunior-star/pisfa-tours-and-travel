<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Credits part or all of a payment to a specific service or invoice.
 *
 * A payment may cover several targets (a group booking paying two services), and
 * a target may be covered by several payments (a deposit then a balance). The
 * unique key on (payment, target) is what stops a retry crediting twice.
 *
 * @property int $amount_minor
 */
class PaymentAllocation extends Model
{
    use HasFactory;

    protected $fillable = [
        'payment_id',
        'allocatable_type',
        'allocatable_id',
        'amount_minor',
        'currency',
        'allocated_by_user_id',
    ];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer'];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function allocatable(): MorphTo
    {
        return $this->morphTo();
    }

    public function allocatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'allocated_by_user_id');
    }

    /**
     * Only allocations backed by a settled payment count towards what a service
     * has actually been paid. A pending intent must never reduce a balance.
     */
    public function scopeSettled(Builder $query): Builder
    {
        return $query->whereHas('payment', fn (Builder $payment): Builder => $payment->whereIn(
            'status',
            PaymentStatus::settledValues(),
        ));
    }

    public function scopeForTarget(Builder $query, Model $target): Builder
    {
        return $query
            ->where('allocatable_type', $target->getMorphClass())
            ->where('allocatable_id', $target->getKey());
    }

    public function formattedAmount(): string
    {
        return Money::format($this->amount_minor, $this->currency);
    }
}
