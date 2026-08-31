<?php

namespace App\Enums;

/**
 * Tier thresholds and discounts are fixed by the product brief.
 *
 * Tier is derived from **lifetime** points earned, never from the spendable
 * balance. Redeeming points must not demote a customer who has already earned
 * their standing.
 */
enum LoyaltyTier: string
{
    case Bronze = 'bronze';
    case Silver = 'silver';
    case Gold = 'gold';
    case Platinum = 'platinum';

    public function label(): string
    {
        return match ($this) {
            self::Bronze => 'Bronze',
            self::Silver => 'Silver',
            self::Gold => 'Gold',
            self::Platinum => 'Platinum',
        };
    }

    /** Lifetime points required to hold this tier. */
    public function threshold(): int
    {
        return match ($this) {
            self::Bronze => 0,
            self::Silver => 1_000,
            self::Gold => 5_000,
            self::Platinum => 20_000,
        };
    }

    /** Whole-percent discount granted by this tier. */
    public function discountPercent(): int
    {
        return match ($this) {
            self::Bronze => 0,
            self::Silver => 5,
            self::Gold => 10,
            self::Platinum => 15,
        };
    }

    /**
     * The tier a lifetime-points total earns. Highest threshold wins.
     */
    public static function forLifetimePoints(int $lifetimePoints): self
    {
        $tier = self::Bronze;

        foreach (self::cases() as $case) {
            if ($lifetimePoints >= $case->threshold() && $case->threshold() >= $tier->threshold()) {
                $tier = $case;
            }
        }

        return $tier;
    }

    /** The next tier up, or null at the top. */
    public function next(): ?self
    {
        return match ($this) {
            self::Bronze => self::Silver,
            self::Silver => self::Gold,
            self::Gold => self::Platinum,
            self::Platinum => null,
        };
    }

    /** Points still needed to reach the next tier, or null at the top. */
    public function pointsToNext(int $lifetimePoints): ?int
    {
        $next = $this->next();

        return $next === null ? null : max(0, $next->threshold() - $lifetimePoints);
    }

    /**
     * Progress towards the next tier as a whole percentage, for the progress
     * bar. Platinum is complete by definition.
     */
    public function progressPercent(int $lifetimePoints): int
    {
        $next = $this->next();

        if ($next === null) {
            return 100;
        }

        $floor = $this->threshold();
        $span = $next->threshold() - $floor;

        if ($span < 1) {
            return 100;
        }

        return (int) min(100, max(0, round(($lifetimePoints - $floor) / $span * 100)));
    }

    /**
     * Apply this tier's discount to a minor-unit amount.
     *
     * Integer arithmetic with an explicit floor: the customer is never charged
     * a fraction, and rounding always favours them.
     */
    public function applyDiscount(int $amountMinor): int
    {
        return intdiv($amountMinor * $this->discountPercent(), 100);
    }
}
