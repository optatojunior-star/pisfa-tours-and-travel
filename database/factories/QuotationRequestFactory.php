<?php

namespace Database\Factories;

use App\Enums\QuotationRequestStatus;
use App\Models\QuotationRequest;
use App\Models\User;
use App\Support\ServiceCatalogue;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<QuotationRequest> */
class QuotationRequestFactory extends Factory
{
    public function definition(): array
    {
        $email = fake()->unique()->safeEmail();

        return [
            'reference' => 'QRQ-'.Str::upper((string) Str::ulid()),
            'tracking_token' => bin2hex(random_bytes(32)),
            // A guest by default: nullable throughout, never a placeholder user.
            'customer_id' => null,
            'status' => QuotationRequestStatus::New,
            'service' => fake()->randomElement(ServiceCatalogue::keys()),
            'contact_name' => fake()->name(),
            'contact_email' => $email,
            'contact_phone' => '+256700'.fake()->numerify('######'),
            'details' => fake()->paragraph(3),
            'idempotency_owner_hash' => hash_hmac('sha256', 'guest|'.$email, (string) config('app.key')),
            'idempotency_key' => fake()->uuid(),
        ];
    }

    public function forCustomer(User $customer): static
    {
        return $this->state(fn (): array => [
            'customer_id' => $customer->getKey(),
            'contact_name' => $customer->name,
            'contact_email' => $customer->email,
            'idempotency_owner_hash' => hash_hmac(
                'sha256',
                'customer|'.$customer->getKey(),
                (string) config('app.key'),
            ),
        ]);
    }

    public function withStatus(QuotationRequestStatus $status): static
    {
        return $this->state(fn (): array => [
            'status' => $status,
            'closed_at' => $status->isOpen() ? null : now(),
            'closure_reason' => $status->isOpen() ? null : 'Closed for testing.',
        ]);
    }
}
