<?php

namespace App\Enums;

/**
 * Every movement in the points ledger. The sign convention is enforced by
 * isCredit(): a credit type may never carry negative points, and a debit type
 * may never carry positive points.
 */
enum LoyaltyTransactionType: string
{
    case Earned = 'earned';
    case ReferralJoin = 'referral_join';
    case ReferralReward = 'referral_reward';
    case Redeemed = 'redeemed';
    case Expired = 'expired';
    case Adjustment = 'adjustment';

    public function label(): string
    {
        return match ($this) {
            self::Earned => 'Points earned',
            self::ReferralJoin => 'Welcome bonus',
            self::ReferralReward => 'Referral reward',
            self::Redeemed => 'Points redeemed',
            self::Expired => 'Points expired',
            self::Adjustment => 'Manual adjustment',
        };
    }

    /** Credits add points; debits remove them. Adjustment may be either. */
    public function isCredit(): bool
    {
        return in_array($this, [self::Earned, self::ReferralJoin, self::ReferralReward], true);
    }

    public function isDebit(): bool
    {
        return in_array($this, [self::Redeemed, self::Expired], true);
    }

    /**
     * Only earned points count towards lifetime standing. Redemptions and
     * expiries must never demote a customer, and an operator adjustment is
     * corrective rather than earned.
     */
    public function countsTowardsLifetime(): bool
    {
        return $this->isCredit();
    }

    /**
     * Whether this movement should reset the inactivity clock. Expiry itself
     * obviously must not, or points could never expire.
     */
    public function refreshesActivity(): bool
    {
        return $this !== self::Expired;
    }
}
