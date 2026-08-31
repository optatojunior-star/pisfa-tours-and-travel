<?php

namespace App\Support\Billing;

use InvalidArgumentException;

/**
 * The single arithmetic for quotation and invoice totals.
 *
 * Everything is integer minor units and integer basis points. Tax is applied
 * once, to the discounted subtotal, rather than per line — rounding each line
 * separately would drift by a unit per line against the figure a customer gets
 * when they add the document up themselves.
 */
final class LineTotals
{
    public const BPS_SCALE = 10_000;

    /**
     * @param  list<array{quantity: int, unit_price_minor: int}>  $items
     * @return array{
     *     subtotal_minor: int,
     *     discount_minor: int,
     *     taxable_minor: int,
     *     tax_amount_minor: int,
     *     total_minor: int,
     *     lines: list<int>
     * }
     */
    public static function compute(array $items, int $discountMinor = 0, int $taxRateBps = 0): array
    {
        if ($discountMinor < 0) {
            throw new InvalidArgumentException('A discount cannot be negative.');
        }

        if ($taxRateBps < 0 || $taxRateBps > self::BPS_SCALE) {
            throw new InvalidArgumentException('A tax rate must be between 0 and 100 percent.');
        }

        $lines = [];
        $subtotal = 0;

        foreach ($items as $item) {
            $quantity = (int) $item['quantity'];
            $unitPrice = (int) $item['unit_price_minor'];

            if ($quantity < 1) {
                throw new InvalidArgumentException('A line quantity must be at least one.');
            }

            if ($unitPrice < 0) {
                throw new InvalidArgumentException('A unit price cannot be negative.');
            }

            $lineTotal = $quantity * $unitPrice;
            $lines[] = $lineTotal;
            $subtotal += $lineTotal;
        }

        // A discount larger than the subtotal would produce a negative total,
        // which no document should ever show.
        $discount = min($discountMinor, $subtotal);
        $taxable = $subtotal - $discount;
        $tax = self::taxOn($taxable, $taxRateBps);

        return [
            'subtotal_minor' => $subtotal,
            'discount_minor' => $discount,
            'taxable_minor' => $taxable,
            'tax_amount_minor' => $tax,
            'total_minor' => $taxable + $tax,
            'lines' => $lines,
        ];
    }

    /** Half-up rounding in integer arithmetic; no float ever touches money. */
    public static function taxOn(int $taxableMinor, int $rateBps): int
    {
        if ($taxableMinor <= 0 || $rateBps <= 0) {
            return 0;
        }

        return intdiv($taxableMinor * $rateBps + intdiv(self::BPS_SCALE, 2), self::BPS_SCALE);
    }

    /** "18%" from 1800, without trailing zeros. */
    public static function formatRate(int $rateBps): string
    {
        $percent = $rateBps / 100;

        return rtrim(rtrim(number_format($percent, 2, '.', ''), '0'), '.').'%';
    }
}
