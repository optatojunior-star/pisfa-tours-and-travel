<?php

namespace Database\Factories;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\User;
use App\Support\Billing\LineTotals;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Invoice> */
class InvoiceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'number' => 'INV-'.now()->format('Y').'-'.Str::upper(Str::random(8)),
            'tracking_token' => bin2hex(random_bytes(32)),
            'customer_id' => null,
            'quotation_id' => null,
            'status' => InvoiceStatus::Draft,
            'contact_name' => fake()->name(),
            'contact_email' => fake()->unique()->safeEmail(),
            'contact_phone' => '+256700'.fake()->numerify('######'),
            'title' => 'Group safari, '.fake()->numberBetween(2, 12).' travellers',
            'currency' => 'UGX',
            'tax_rate_bps' => 0,
            'due_on' => now()->addDays(14)->toDateString(),
        ];
    }

    public function forCustomer(User $customer): static
    {
        return $this->state(fn (): array => [
            'customer_id' => $customer->getKey(),
            'contact_name' => $customer->name,
            'contact_email' => $customer->email,
        ]);
    }

    public function withStatus(InvoiceStatus $status): static
    {
        return $this->state(fn (): array => [
            'status' => $status,
            'issued_on' => $status->isVisibleToCustomer() ? now()->toDateString() : null,
            'issued_at' => $status->isVisibleToCustomer() ? now() : null,
            'paid_at' => $status === InvoiceStatus::Paid ? now() : null,
            'cancelled_at' => $status === InvoiceStatus::Cancelled ? now() : null,
            'voided_at' => $status === InvoiceStatus::Void ? now() : null,
        ]);
    }

    public function dueOn(string $date): static
    {
        return $this->state(fn (): array => ['due_on' => $date]);
    }

    /**
     * @param  list<array{description?: string, quantity: int, unit_price_minor: int}>  $lines
     */
    public function withItems(array $lines, int $discountMinor = 0, int $taxRateBps = 0): static
    {
        return $this->afterCreating(function (Invoice $invoice) use ($lines, $discountMinor, $taxRateBps): void {
            $totals = LineTotals::compute($lines, $discountMinor, $taxRateBps);

            foreach (array_values($lines) as $index => $line) {
                InvoiceItem::query()->create([
                    'invoice_id' => $invoice->getKey(),
                    'sort_order' => $index,
                    'description' => $line['description'] ?? 'Line '.($index + 1),
                    'quantity' => $line['quantity'],
                    'unit_price_minor' => $line['unit_price_minor'],
                    'line_total_minor' => $totals['lines'][$index],
                ]);
            }

            $invoice->forceFill([
                'subtotal_minor' => $totals['subtotal_minor'],
                'discount_minor' => $totals['discount_minor'],
                'tax_rate_bps' => $taxRateBps,
                'tax_amount_minor' => $totals['tax_amount_minor'],
                'total_minor' => $totals['total_minor'],
            ])->save();
        });
    }
}
