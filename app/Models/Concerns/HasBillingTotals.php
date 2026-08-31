<?php

namespace App\Models\Concerns;

use App\Support\Billing\LineTotals;
use App\Support\Money;

/**
 * Formatting and derived figures shared by quotations and invoices.
 *
 * The arithmetic itself lives in LineTotals; this only reads the stored columns
 * so a document renders identically wherever it appears.
 */
trait HasBillingTotals
{
    public function formattedSubtotal(): string
    {
        return Money::format((int) $this->subtotal_minor, $this->currency);
    }

    public function formattedDiscount(): string
    {
        return Money::format((int) $this->discount_minor, $this->currency);
    }

    public function formattedTax(): string
    {
        return Money::format((int) $this->tax_amount_minor, $this->currency);
    }

    public function formattedTotal(): string
    {
        return Money::format((int) $this->total_minor, $this->currency);
    }

    public function taxableMinor(): int
    {
        return max(0, (int) $this->subtotal_minor - (int) $this->discount_minor);
    }

    public function formattedTaxable(): string
    {
        return Money::format($this->taxableMinor(), $this->currency);
    }

    public function taxLabel(): string
    {
        return (string) config('billing.tax.label', 'VAT')
            .' '.LineTotals::formatRate((int) $this->tax_rate_bps);
    }

    public function hasDiscount(): bool
    {
        return (int) $this->discount_minor > 0;
    }

    public function hasTax(): bool
    {
        return (int) $this->tax_amount_minor > 0;
    }
}
