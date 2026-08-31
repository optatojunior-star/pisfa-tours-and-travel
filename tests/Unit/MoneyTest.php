<?php

namespace Tests\Unit;

use App\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    /** @return array<string, array{string|int, string, int}> */
    public static function exactAmounts(): array
    {
        return [
            'zero US dollars' => ['0', 'USD', 0],
            'one US cent' => ['0.01', 'USD', 1],
            'one decimal is padded' => ['19.9', 'USD', 1990],
            'two decimals remain exact' => ['19.99', 'USD', 1999],
            'large USD amount' => ['123456789.07', 'USD', 12_345_678_907],
            'whole Uganda shillings' => ['250000', 'UGX', 250_000],
            'integer input' => [5000, 'UGX', 5000],
            'currency is case insensitive' => ['12.30', 'usd', 1230],
        ];
    }

    #[DataProvider('exactAmounts')]
    public function test_major_amounts_are_converted_to_exact_integer_minor_units(
        string|int $amount,
        string $currency,
        int $expected,
    ): void {
        $this->assertSame($expected, Money::parse($amount, $currency));
    }

    /** @return array<string, array{string, string}> */
    public static function invalidAmounts(): array
    {
        return [
            'negative' => ['-1', 'USD'],
            'plus sign' => ['+1', 'USD'],
            'separator' => ['1,000', 'USD'],
            'scientific notation' => ['1e3', 'USD'],
            'leading zero' => ['01.00', 'USD'],
            'bare decimal point' => ['1.', 'USD'],
            'fraction without whole part' => ['.50', 'USD'],
            'too many USD decimals' => ['1.001', 'USD'],
            'UGX fraction' => ['1.0', 'UGX'],
            'empty value' => ['', 'USD'],
        ];
    }

    #[DataProvider('invalidAmounts')]
    public function test_ambiguous_or_inexact_amounts_are_rejected(string $amount, string $currency): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::parse($amount, $currency);
    }

    public function test_unsupported_currencies_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported currency.');

        Money::parse('10', 'EUR');
    }

    public function test_values_larger_than_the_supported_integer_range_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('too large');

        Money::parse('999999999999999999.99', 'USD');
    }

    public function test_minor_units_are_formatted_without_floating_point_math(): void
    {
        $this->assertSame('USD 0.01', Money::format(1, 'USD'));
        $this->assertSame('USD 12,345.60', Money::format(1_234_560, 'USD'));
        $this->assertSame('UGX 250,000', Money::format(250_000, 'UGX'));
        $this->assertSame('-USD 19.99', Money::format(-1999, 'USD'));
    }

    public function test_minor_units_are_rendered_as_exact_float_free_form_input(): void
    {
        $this->assertSame('250000', Money::forInput(250_000, 'UGX'));
        $this->assertSame('0', Money::forInput(0, 'UGX'));
        $this->assertSame('0.01', Money::forInput(1, 'USD'));
        $this->assertSame('19.90', Money::forInput(1990, 'USD'));
        $this->assertSame('123456789.07', Money::forInput(12_345_678_907, 'USD'));
        $this->assertSame('-19.99', Money::forInput(-1999, 'USD'));
        $this->assertSame('-250000', Money::forInput(-250_000, 'UGX'));
    }

    public function test_non_negative_minor_units_round_trip_through_form_input_exactly(): void
    {
        foreach ([0, 1, 99, 1990, 12_345_678_907] as $minorAmount) {
            $this->assertSame(
                $minorAmount,
                Money::parse(Money::forInput($minorAmount, 'USD'), 'USD'),
            );
        }

        foreach ([0, 1, 250_000, 9_007_199_254_740_991] as $minorAmount) {
            $this->assertSame(
                $minorAmount,
                Money::parse(Money::forInput($minorAmount, 'UGX'), 'UGX'),
            );
        }
    }
}
