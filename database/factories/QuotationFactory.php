<?php

namespace Database\Factories;

use App\Enums\QuotationStatus;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\User;
use App\Support\Billing\LineTotals;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Quotation> */
class QuotationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'number' => 'QTN-'.now()->format('Y').'-'.Str::upper(Str::random(8)),
            'tracking_token' => bin2hex(random_bytes(32)),
            'customer_id' => null,
            'status' => QuotationStatus::Draft,
            'contact_name' => fake()->name(),
            'contact_email' => fake()->unique()->safeEmail(),
            'contact_phone' => '+256700'.fake()->numerify('######'),
            'title' => 'Group safari, '.fake()->numberBetween(2, 12).' travellers',
            'currency' => 'UGX',
            'tax_rate_bps' => 0,
            'valid_until' => now()->addDays(14)->toDateString(),
            'revision' => 1,
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

    public function withStatus(QuotationStatus $status): static
    {
        return $this->state(fn (): array => [
            'status' => $status,
            'sent_at' => in_array($status, [
                QuotationStatus::Sent,
                QuotationStatus::Accepted,
                QuotationStatus::Declined,
                QuotationStatus::Expired,
            ], true) ? now()->subDay() : null,
            'accepted_at' => $status === QuotationStatus::Accepted ? now() : null,
            'declined_at' => $status === QuotationStatus::Declined ? now() : null,
            'expired_at' => $status === QuotationStatus::Expired ? now() : null,
            'cancelled_at' => $status === QuotationStatus::Cancelled ? now() : null,
        ]);
    }

    public function expiringOn(string $date): static
    {
        return $this->state(fn (): array => ['valid_until' => $date]);
    }

    /**
     * Attaches real line items and restamps the header totals from them, so a
     * factory-built quotation is internally consistent.
     *
     * @param  list<array{description?: string, quantity: int, unit_price_minor: int}>  $lines
     */
    public function withItems(array $lines, int $discountMinor = 0, int $taxRateBps = 0): static
    {
        return $this->afterCreating(function (Quotation $quotation) use ($lines, $discountMinor, $taxRateBps): void {
            $totals = LineTotals::compute($lines, $discountMinor, $taxRateBps);

            foreach (array_values($lines) as $index => $line) {
                QuotationItem::query()->create([
                    'quotation_id' => $quotation->getKey(),
                    'sort_order' => $index,
                    'description' => $line['description'] ?? 'Line '.($index + 1),
                    'quantity' => $line['quantity'],
                    'unit_price_minor' => $line['unit_price_minor'],
                    'line_total_minor' => $totals['lines'][$index],
                ]);
            }

            $quotation->forceFill([
                'subtotal_minor' => $totals['subtotal_minor'],
                'discount_minor' => $totals['discount_minor'],
                'tax_rate_bps' => $taxRateBps,
                'tax_amount_minor' => $totals['tax_amount_minor'],
                'total_minor' => $totals['total_minor'],
            ])->save();
        });
    }
}
