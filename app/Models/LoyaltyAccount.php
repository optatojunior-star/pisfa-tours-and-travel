<?php

namespace App\Models;

use App\Enums\LoyaltyTier;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property LoyaltyTier $tier
 * @property int $points_balance
 * @property int $lifetime_points
 * @property string $referral_code
 * @property CarbonImmutable|null $last_activity_at
 * @property CarbonImmutable|null $expiry_warned_at
 * @property User|null $user
 */
class LoyaltyAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'points_balance',
        'lifetime_points',
        'tier',
        'referral_code',
        'last_activity_at',
        'expiry_warned_at',
    ];

    protected function casts(): array
    {
        return [
            'tier' => LoyaltyTier::class,
            'points_balance' => 'integer',
            'lifetime_points' => 'integer',
            'last_activity_at' => 'immutable_datetime',
            'expiry_warned_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(LoyaltyTransaction::class)->latest('id');
    }

    public function referrals(): HasMany
    {
        return $this->hasMany(Referral::class, 'referrer_account_id')->latest('id');
    }

    /**
     * Accounts whose balance has sat untouched past the inactivity window.
     */
    public function scopeExpirable(Builder $query, ?\DateTimeInterface $at = null): Builder
    {
        $cutoff = CarbonImmutable::instance($at ?? now())
            ->subMonths((int) config('loyalty.expiry.inactivity_months', 12));

        return $query
            ->where('points_balance', '>', 0)
            ->whereNotNull('last_activity_at')
            ->where('last_activity_at', '<=', $cutoff);
    }

    /**
     * Accounts inside the warning window that have not been warned since their
     * last activity. Re-checking the warning against activity means a customer
     * who returns and then lapses again is warned a second time.
     */
    public function scopeDueExpiryWarning(Builder $query, ?\DateTimeInterface $at = null): Builder
    {
        $now = CarbonImmutable::instance($at ?? now());
        $months = (int) config('loyalty.expiry.inactivity_months', 12);
        $warnDays = (int) config('loyalty.expiry.warning_days_before', 30);

        $warnFrom = $now->subMonths($months)->addDays($warnDays);

        return $query
            ->where('points_balance', '>', 0)
            ->whereNotNull('last_activity_at')
            ->where('last_activity_at', '<=', $warnFrom)
            ->where('last_activity_at', '>', $now->subMonths($months))
            ->where(function (Builder $unwarned): void {
                $unwarned
                    ->whereNull('expiry_warned_at')
                    ->orWhereColumn('expiry_warned_at', '<', 'last_activity_at');
            });
    }

    /** The date this balance expires if nothing else happens. */
    public function expiresOn(): ?CarbonImmutable
    {
        if ($this->last_activity_at === null || $this->points_balance < 1) {
            return null;
        }

        return $this->last_activity_at->addMonths(
            (int) config('loyalty.expiry.inactivity_months', 12),
        );
    }

    public function minimumRedemption(): int
    {
        return (int) config('loyalty.redemption.minimum_points', 500);
    }

    public function canRedeem(): bool
    {
        return $this->points_balance >= $this->minimumRedemption();
    }

    /** Monetary value of a points quantity, in base-currency minor units. */
    public static function pointsValueMinor(int $points): int
    {
        return max(0, $points) * (int) config('loyalty.redemption.minor_units_per_point', 100);
    }

    public function balanceValueMinor(): int
    {
        return self::pointsValueMinor($this->points_balance);
    }

    public function formattedBalanceValue(): string
    {
        return Money::format(
            $this->balanceValueMinor(),
            (string) config('payments.base_currency', 'UGX'),
        );
    }

    public function pointsToNextTier(): ?int
    {
        return $this->tier->pointsToNext($this->lifetime_points);
    }

    public function tierProgressPercent(): int
    {
        return $this->tier->progressPercent($this->lifetime_points);
    }

    public function referralUrl(): string
    {
        return route('register', ['ref' => $this->referral_code]);
    }

    /**
     * Codes are uppercase alphanumeric without easily-confused characters, so
     * they survive being read aloud or copied off a screen.
     */
    public static function generateReferralCode(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

        do {
            $code = 'PISFA';

            for ($i = 0; $i < 5; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
        } while (self::query()->where('referral_code', $code)->exists());

        return $code;
    }

    /** Find or create the account for a customer. */
    public static function forUser(User $user): self
    {
        return self::query()->firstOrCreate(
            ['user_id' => $user->getKey()],
            [
                'referral_code' => self::generateReferralCode(),
                'tier' => LoyaltyTier::Bronze,
            ],
        );
    }
}
