<?php

namespace Database\Factories;

use App\Enums\CorporateAccountStatus;
use App\Models\CorporateAccount;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<CorporateAccount> */
class CorporateAccountFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->company();

        return [
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'name' => $name,
            'registration_number' => 'REG'.fake()->unique()->numerify('######'),
            'industry' => 'Non-governmental organisation',
            'billing_contact_name' => fake()->name(),
            'billing_contact_email' => fake()->unique()->companyEmail(),
            'billing_contact_phone' => '+2564'.fake()->numerify('########'),
            'billing_address' => 'Plot 12, Kampala Road, Kampala',
            'status' => CorporateAccountStatus::Prospect,
            'payment_terms_days' => 30,
            // UGX has no minor unit, so the integer is whole shillings.
            'credit_limit_minor' => 20_000_000,
            'currency' => 'UGX',
            'discount_bps' => 0,
        ];
    }

    public function active(): static
    {
        return $this->state(fn (): array => [
            'status' => CorporateAccountStatus::Active,
            'activated_at' => now()->subMonths(3),
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn (): array => [
            'status' => CorporateAccountStatus::Suspended,
            'activated_at' => now()->subMonths(6),
            'suspended_at' => now()->subDay(),
            'suspension_reason' => 'Two invoices past due.',
        ]);
    }

    public function closed(): static
    {
        return $this->state(fn (): array => [
            'status' => CorporateAccountStatus::Closed,
            'closed_at' => now()->subDay(),
            'closure_reason' => 'The contract was not renewed.',
        ]);
    }

    public function withCreditLimit(int $minor, string $currency = 'UGX'): static
    {
        return $this->state(fn (): array => [
            'credit_limit_minor' => $minor,
            'currency' => $currency,
        ]);
    }

    public function discounted(int $bps): static
    {
        return $this->state(fn (): array => ['discount_bps' => $bps]);
    }
}
