<?php

namespace App\Support\Payroll;

use InvalidArgumentException;

/**
 * Statutory deductions, in integer minor units.
 *
 * All arithmetic is integer basis points with the division last and half the
 * divisor added first, so a rate is never a float and a rounding step never
 * quietly favours the employer.
 *
 * The order is the whole point: NSSF comes off gross, and PAYE is charged on
 * what is left. Computing PAYE on the full gross would overstate the tax and
 * short the employee every single month.
 */
final class TaxSchedule
{
    /** The employee's own NSSF contribution, deducted from gross. */
    public static function employeeNssf(int $grossMinor, string $currency): int
    {
        if (! (bool) config('payroll.nssf.enabled', true) || $grossMinor < 1) {
            return 0;
        }

        return self::applyRate($grossMinor, (int) config('payroll.nssf.employee_bps', 500));
    }

    /**
     * The employer's NSSF contribution.
     *
     * Never deducted from the employee — it is a cost the business carries on
     * top of the salary, and putting it on the payslip as a deduction would be
     * wrong by a factor of three.
     */
    public static function employerNssf(int $grossMinor, string $currency): int
    {
        if (! (bool) config('payroll.nssf.enabled', true) || $grossMinor < 1) {
            return 0;
        }

        return self::applyRate($grossMinor, (int) config('payroll.nssf.employer_bps', 1000));
    }

    /**
     * PAYE on the chargeable amount, using the configured monthly bands.
     *
     * The bands are written in one currency. Charging them against a salary
     * denominated in another would be applying Ugandan shilling thresholds to
     * dollars, so that is refused rather than guessed at.
     */
    public static function paye(int $chargeableMinor, string $currency): int
    {
        if (! (bool) config('payroll.paye.enabled', true) || $chargeableMinor < 1) {
            return 0;
        }

        $bandCurrency = strtoupper((string) config('payroll.paye.currency', 'UGX'));

        if (strtoupper($currency) !== $bandCurrency) {
            throw new InvalidArgumentException(
                "PAYE bands are defined in {$bandCurrency}, so they cannot be applied to a {$currency} salary."
            );
        }

        $tax = 0;
        $floor = 0;

        /** @var list<array{up_to: int|null, rate_bps: int}> $bands */
        $bands = config('payroll.paye.bands', []);

        foreach ($bands as $band) {
            $ceiling = $band['up_to'] ?? null;

            // Everything above the previous band's top, capped at this one's.
            $slice = $ceiling === null
                ? $chargeableMinor - $floor
                : min($chargeableMinor, $ceiling) - $floor;

            if ($slice > 0) {
                $tax += self::applyRate($slice, (int) $band['rate_bps']);
            }

            if ($ceiling === null || $chargeableMinor <= $ceiling) {
                break;
            }

            $floor = $ceiling;
        }

        $surchargeAbove = config('payroll.paye.surcharge_above');

        if (is_int($surchargeAbove) && $chargeableMinor > $surchargeAbove) {
            $tax += self::applyRate(
                $chargeableMinor - $surchargeAbove,
                (int) config('payroll.paye.surcharge_bps', 0),
            );
        }

        return $tax;
    }

    /**
     * Basis points of an integer amount, rounded half up.
     *
     * intdiv() truncates, which over a year would shave real money off in one
     * direction only; adding half the divisor first rounds instead.
     */
    public static function applyRate(int $amountMinor, int $bps): int
    {
        if ($amountMinor < 1 || $bps < 1) {
            return 0;
        }

        if ($amountMinor > intdiv(PHP_INT_MAX - 5000, $bps)) {
            throw new InvalidArgumentException('That amount is too large to apply a rate to.');
        }

        return intdiv($amountMinor * $bps + 5000, 10000);
    }

    /** Whether PAYE can be charged at all in the given currency. */
    public static function supportsPaye(string $currency): bool
    {
        if (! (bool) config('payroll.paye.enabled', true)) {
            return false;
        }

        return strtoupper($currency) === strtoupper((string) config('payroll.paye.currency', 'UGX'));
    }
}
