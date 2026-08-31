<?php

namespace App\Services\Payments;

use App\Support\Money;
use InvalidArgumentException;

/**
 * Converts between supported currencies using a configured rate.
 *
 * Rates are held as **parts per million** integers, never floats: a float rate
 * multiplied into a minor-unit amount reintroduces exactly the rounding error
 * the integer money design exists to avoid.
 *
 * The resolved rate is stamped onto each payment at creation. Changing the
 * configured rate later must never alter historic revenue, so nothing reads
 * this class to re-derive an old payment's base amount.
 */
class ExchangeRateResolver
{
    public const SCALE = 1_000_000;

    public function baseCurrency(): string
    {
        return strtoupper((string) config('payments.base_currency', 'UGX'));
    }

    /**
     * Rate expressed as parts-per-million of `$to` per one unit of `$from`.
     */
    public function rate(string $from, string $to): int
    {
        $from = strtoupper(trim($from));
        $to = strtoupper(trim($to));

        if ($from === $to) {
            return self::SCALE;
        }

        $configured = config('payments.exchange_rates.'.$from.'_'.$to);

        if ($configured !== null) {
            return $this->assertPositive((int) $configured, $from, $to);
        }

        // Fall back to the inverse of the declared pair rather than silently
        // treating an unconfigured pair as 1:1, which would misprice by orders
        // of magnitude for UGX/USD.
        $inverse = config('payments.exchange_rates.'.$to.'_'.$from);

        if ($inverse !== null) {
            $inverse = $this->assertPositive((int) $inverse, $to, $from);

            return intdiv(self::SCALE * self::SCALE, $inverse);
        }

        throw new InvalidArgumentException("No exchange rate is configured for {$from} to {$to}.");
    }

    /**
     * Convert a minor-unit amount between currencies of differing exponents.
     *
     * UGX has exponent 0 and USD exponent 2, so the conversion must move
     * through the scale difference as well as the rate. All arithmetic stays in
     * integers; the single rounding step is explicit and happens last.
     */
    public function convert(int $amountMinor, string $from, string $to, ?int $rate = null): int
    {
        $from = strtoupper(trim($from));
        $to = strtoupper(trim($to));

        if ($from === $to) {
            return $amountMinor;
        }

        $rate ??= $this->rate($from, $to);
        $this->assertPositive($rate, $from, $to);

        $fromExponent = Money::exponent($from);
        $toExponent = Money::exponent($to);

        // value = amount * rate / SCALE, adjusted for the exponent difference.
        $numerator = $amountMinor * $rate;
        $denominator = self::SCALE;

        if ($toExponent > $fromExponent) {
            $numerator *= 10 ** ($toExponent - $fromExponent);
        } elseif ($fromExponent > $toExponent) {
            $denominator *= 10 ** ($fromExponent - $toExponent);
        }

        // Half-up on the absolute value, so rounding never depends on sign.
        $negative = $numerator < 0;
        $absolute = abs($numerator);
        $rounded = intdiv($absolute * 2 + $denominator, $denominator * 2);

        return $negative ? -$rounded : $rounded;
    }

    /**
     * @return array{amount_minor: int, currency: string, rate_ppm: int}
     */
    public function toBase(int $amountMinor, string $currency): array
    {
        $base = $this->baseCurrency();
        $rate = $this->rate($currency, $base);

        return [
            'amount_minor' => $this->convert($amountMinor, $currency, $base, $rate),
            'currency' => $base,
            'rate_ppm' => $rate,
        ];
    }

    private function assertPositive(int $rate, string $from, string $to): int
    {
        if ($rate < 1) {
            throw new InvalidArgumentException(
                "The configured {$from} to {$to} exchange rate must be a positive integer.",
            );
        }

        return $rate;
    }
}
