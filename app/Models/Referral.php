<?php

namespace App\Models;

use App\Enums\ReferralStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property ReferralStatus $status
 * @property CarbonImmutable|null $qualified_at
 * @property CarbonImmutable|null $rewarded_at
 */
class Referral extends Model
{
    use HasFactory;

    protected $fillable = [
        'referrer_account_id',
        'referred_user_id',
        'code_used',
        'status',
        'qualified_at',
        'rewarded_at',
        'void_reason',
    ];

    protected function casts(): array
    {
        return [
            'status' => ReferralStatus::class,
            'qualified_at' => 'immutable_datetime',
            'rewarded_at' => 'immutable_datetime',
        ];
    }

    public function referrerAccount(): BelongsTo
    {
        return $this->belongsTo(LoyaltyAccount::class, 'referrer_account_id');
    }

    public function referredUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_user_id');
    }

    /** Qualified but not yet paid: the queue the reward job works through. */
    public function scopeAwaitingReward(Builder $query): Builder
    {
        return $query->where('status', ReferralStatus::Qualified->value);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', ReferralStatus::Pending->value);
    }

    public function canTransitionTo(ReferralStatus $next): bool
    {
        return $this->status->canTransitionTo($next);
    }
}
