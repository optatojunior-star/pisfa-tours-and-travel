<?php

namespace App\Enums;

/**
 * How an owner is paid for their vehicle.
 *
 * The two models put the risk in different places, which is the whole point of
 * offering both: a retainer pays the same whether the car earns or not, and a
 * share pays nothing in a quiet month but more in a busy one.
 */
enum LeasePayoutModel: string
{
    case FixedMonthly = 'fixed_monthly';
    case RevenueShare = 'revenue_share';

    public function label(): string
    {
        return match ($this) {
            self::FixedMonthly => 'Fixed monthly retainer',
            self::RevenueShare => 'Share of hire revenue',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::FixedMonthly => 'The same amount every month, whether the vehicle is hired out or not.',
            self::RevenueShare => 'An agreed percentage of what the vehicle actually earns on hire.',
        };
    }

    public function usesRetainer(): bool
    {
        return $this === self::FixedMonthly;
    }

    public function usesShare(): bool
    {
        return $this === self::RevenueShare;
    }
}
