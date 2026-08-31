<?php

namespace App\Support;

use InvalidArgumentException;

final class Money
{
    public static function exponent(string $currency): int
    {
        return match (strtoupper($currency)) {
            'UGX' => 0,
            'USD' => 2,
            default => throw new InvalidArgumentException('Unsupported currency.'),
        };
    }

    public static function parse(string|int $amount, string $currency): int
    {
        $value = trim((string) $amount);

        if (! preg_match('/^(?:0|[1-9]\d*)(?:\.(\d+))?$/', $value, $matches)) {
            throw new InvalidArgumentException('Enter a valid non-negative amount without separators.');
        }

        $exponent = self::exponent($currency);
        $fraction = $matches[1] ?? '';

        if (strlen($fraction) > $exponent) {
            throw new InvalidArgumentException("{$currency} accepts at most {$exponent} decimal places.");
        }

        if ($exponent === 0 && $fraction !== '') {
            throw new InvalidArgumentException("{$currency} amounts must be whole numbers.");
        }

        $whole = explode('.', $value, 2)[0];
        $whole = ltrim($whole, '0');
        $whole = $whole === '' ? '0' : $whole;
        $minor = $whole.str_pad($fraction, $exponent, '0');

        $maximum = (string) PHP_INT_MAX;

        if (strlen($minor) > strlen($maximum)
            || (strlen($minor) === strlen($maximum) && strcmp($minor, $maximum) > 0)) {
            throw new InvalidArgumentException('The amount is too large.');
        }

        return (int) $minor;
    }

    public static function format(int $minorAmount, string $currency): string
    {
        $currency = strtoupper($currency);
        $exponent = self::exponent($currency);
        $negative = $minorAmount < 0;
        $digits = (string) abs($minorAmount);

        if ($exponent === 0) {
            $number = number_format((int) $digits, 0, '.', ',');
        } else {
            $digits = str_pad($digits, $exponent + 1, '0', STR_PAD_LEFT);
            $whole = substr($digits, 0, -$exponent);
            $fraction = substr($digits, -$exponent);
            $number = number_format((int) $whole, 0, '.', ',').'.'.$fraction;
        }

        return ($negative ? '-' : '').$currency.' '.$number;
    }

    public static function forInput(int $minorAmount, string $currency): string
    {
        $exponent = self::exponent($currency);

        if ($exponent === 0) {
            return (string) $minorAmount;
        }

        $negative = $minorAmount < 0;
        $absolute = abs($minorAmount);
        $scale = 10 ** $exponent;

        return ($negative ? '-' : '')
            .intdiv($absolute, $scale)
            .'.'
            .str_pad((string) ($absolute % $scale), $exponent, '0', STR_PAD_LEFT);
    }
}
