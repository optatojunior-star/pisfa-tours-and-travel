<?php

namespace Database\Factories;

use App\Enums\SalesEnquiryStatus;
use App\Models\User;
use App\Models\VehicleListing;
use App\Models\VehicleSalesEnquiry;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<VehicleSalesEnquiry> */
class VehicleSalesEnquiryFactory extends Factory
{
    public function definition(): array
    {
        $key = (string) Str::uuid();

        return [
            'reference' => 'SEQ-'.Str::upper((string) Str::ulid()),
            'vehicle_listing_id' => VehicleListing::factory(),
            'customer_id' => null,
            'assigned_to_user_id' => null,
            'status' => SalesEnquiryStatus::New,
            'contact_name' => fake()->name(),
            'contact_email' => fake()->unique()->safeEmail(),
            'contact_phone' => '+2567'.fake()->numerify('########'),
            'message' => fake()->sentence(12),
            'offer_minor' => null,
            'offer_currency' => null,
            // Mirrors what the action stores, so factory-made rows collide the
            // same way real ones would.
            'idempotency_owner_hash' => hash_hmac('sha256', $key, (string) config('app.key')),
            'idempotency_key' => $key,
        ];
    }

    public function for_(VehicleListing $listing): static
    {
        return $this->state(fn (): array => ['vehicle_listing_id' => $listing->getKey()]);
    }

    public function fromCustomer(User $customer): static
    {
        return $this->state(fn (): array => [
            'customer_id' => $customer->getKey(),
            'contact_name' => $customer->name,
            'contact_email' => $customer->email,
        ]);
    }

    public function assignedTo(User $staff): static
    {
        return $this->state(fn (): array => ['assigned_to_user_id' => $staff->getKey()]);
    }

    public function status(SalesEnquiryStatus $status): static
    {
        return $this->state(fn (): array => [
            'status' => $status,
            'closed_at' => $status->isOpen() ? null : now()->subDay(),
            'closure_reason' => $status === SalesEnquiryStatus::Lost ? 'Bought elsewhere.' : null,
        ]);
    }

    public function offering(int $minor, string $currency = 'UGX'): static
    {
        return $this->state(fn (): array => [
            'offer_minor' => $minor,
            'offer_currency' => $currency,
        ]);
    }
}
