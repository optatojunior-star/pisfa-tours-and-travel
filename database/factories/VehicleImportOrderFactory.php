<?php

namespace Database\Factories;

use App\Enums\VehicleImportBodyType;
use App\Enums\VehicleImportDriveType;
use App\Enums\VehicleImportFuelType;
use App\Enums\VehicleImportStatus;
use App\Enums\VehicleImportSteering;
use App\Enums\VehicleImportTransmission;
use App\Models\User;
use App\Models\VehicleImportOrder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<VehicleImportOrder> */
class VehicleImportOrderFactory extends Factory
{
    public function definition(): array
    {
        $email = fake()->unique()->safeEmail();
        $phone = '+256700'.fake()->unique()->numerify('######');
        $year = fake()->numberBetween(2015, 2022);

        return [
            'reference' => 'IMP-'.Str::upper((string) Str::ulid()),
            'tracking_token' => bin2hex(random_bytes(32)),
            'customer_id' => null,
            'status' => VehicleImportStatus::Inquiry,
            'make' => fake()->randomElement(['Toyota', 'Nissan', 'Mazda', 'Subaru']),
            'model' => fake()->randomElement(['Harrier', 'X-Trail', 'CX-5', 'Forester']),
            'year_from' => $year,
            'year_to' => $year + 2,
            'body_type' => VehicleImportBodyType::Suv,
            'fuel_type' => VehicleImportFuelType::Petrol,
            'transmission' => VehicleImportTransmission::Automatic,
            'drive_type' => VehicleImportDriveType::FourWheelDrive,
            'steering' => VehicleImportSteering::RightHand,
            'engine_capacity_cc' => 2000,
            'origin_country' => 'JP',
            'maximum_mileage_km' => 120000,
            'auction_grade' => '4.5',
            'preferred_colour' => 'Pearl white',
            'units' => 1,
            'purpose' => 'personal',
            'notes' => null,
            'budget_minor' => 90_000_000,
            'budget_currency' => 'UGX',
            'contact_name' => fake()->name(),
            'contact_email' => $email,
            'contact_phone' => $phone,
            'idempotency_owner_hash' => hash('sha256', $email.'|'.$phone.'|'.Str::random(8)),
            'idempotency_key' => (string) Str::uuid(),
        ];
    }

    public function forCustomer(User $customer): static
    {
        return $this->state(fn (): array => [
            'customer_id' => $customer->getKey(),
            'contact_name' => $customer->name,
            'contact_email' => $customer->email,
            'contact_phone' => $customer->phone ?? '+256700000000',
        ]);
    }

    public function withStatus(VehicleImportStatus $status): static
    {
        return $this->state(fn (): array => ['status' => $status]);
    }

    /**
     * A published quotation. Defaults to UGX 120,000,000 total with a
     * UGX 30,000,000 deposit so deposit-then-balance is easy to assert.
     */
    public function quoted(int $total = 120_000_000, int $deposit = 30_000_000): static
    {
        return $this->state(fn (): array => [
            'status' => VehicleImportStatus::Quoted,
            'total_price_minor' => $total,
            'deposit_minor' => $deposit,
            'quote_currency' => 'UGX',
            'quoted_at' => now(),
            'quote_expires_at' => now()->addDays(14),
            'estimated_arrival_on' => now()->addDays(60)->toDateString(),
        ]);
    }

    public function expiredQuote(): static
    {
        return $this->quoted()->state(fn (): array => [
            'quoted_at' => now()->subDays(30),
            'quote_expires_at' => now()->subDay(),
        ]);
    }
}
