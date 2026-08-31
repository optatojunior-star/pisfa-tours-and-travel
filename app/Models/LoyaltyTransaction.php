<?php

namespace App\Models;

use App\Enums\LoyaltyTransactionType;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One movement in the points ledger. Append-only: a correction is a new
 * Adjustment row, never an edit, so the history stays auditable.
 *
 * @property LoyaltyTransactionType $type
 * @property int $points
 * @property int $balance_after
 * @property array<string, mixed>|null $payload
 */
class LoyaltyTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'loyalty_account_id',
        'type',
        'points',
        'balance_after',
        'source_type',
        'source_id',
        'description',
        'payload',
        'actor_user_id',
        'idempotency_key',
    ];

    protected $hidden = ['idempotency_key'];

    protected function casts(): array
    {
        return [
            'type' => LoyaltyTransactionType::class,
            'points' => 'integer',
            'balance_after' => 'integer',
            'payload' => 'array',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(LoyaltyAccount::class, 'loyalty_account_id');
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function scopeCredits(Builder $query): Builder
    {
        return $query->where('points', '>', 0);
    }

    public function scopeDebits(Builder $query): Builder
    {
        return $query->where('points', '<', 0);
    }

    public function isCredit(): bool
    {
        return $this->points > 0;
    }

    /** Signed display, so a ledger row reads unambiguously. */
    public function signedPoints(): string
    {
        return ($this->points > 0 ? '+' : '').number_format($this->points);
    }

    /** Monetary value of this movement, for redemption rows. */
    public function valueMinor(): int
    {
        return LoyaltyAccount::pointsValueMinor(abs($this->points));
    }

    public function formattedValue(): string
    {
        return Money::format($this->valueMinor(), (string) config('payments.base_currency', 'UGX'));
    }
}
