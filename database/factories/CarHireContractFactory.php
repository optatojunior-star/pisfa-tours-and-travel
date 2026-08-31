<?php

namespace Database\Factories;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Models\CarHireBooking;
use App\Models\CarHireContract;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<CarHireContract> */
class CarHireContractFactory extends Factory
{
    public function definition(): array
    {
        return [
            'car_hire_booking_id' => CarHireBooking::factory()->confirmed(),
            'contract_number' => 'HIRE-CONTRACT-'.Str::upper((string) Str::ulid()),
            'version' => 1,
            'template_version' => (string) config('car_hire.contract.version', '2026-08-20'),
            'snapshot' => fn (array $attributes): array => $this->snapshot($attributes),
            'terms_snapshot' => 'Safe test rental terms. This fixture is not production legal wording.',
            'content_sha256' => fn (array $attributes): string => hash(
                'sha256',
                json_encode($attributes['snapshot'], JSON_THROW_ON_ERROR).'\n'.$attributes['terms_snapshot'],
            ),
            'issued_at' => now(),
            'accepted_at' => null,
            'accepted_by_user_id' => null,
            'acceptance_ip' => null,
            'acceptance_user_agent' => null,
            'voided_at' => null,
            'voided_by_user_id' => null,
            'void_reason' => null,
        ];
    }

    public function accepted(): static
    {
        return $this->state(fn (array $attributes): array => [
            'accepted_at' => now(),
            'accepted_by_user_id' => CarHireBooking::query()
                ->findOrFail($attributes['car_hire_booking_id'])->customer_id,
            'acceptance_ip' => '127.0.0.1',
            'acceptance_user_agent' => 'PISFA test agent',
        ]);
    }

    public function voided(): static
    {
        return $this->state(fn (): array => [
            'voided_at' => now(),
            'voided_by_user_id' => User::factory()->state([
                'role' => UserRole::Staff,
                'status' => AccountStatus::Active,
                'email_verified_at' => now(),
            ]),
            'void_reason' => 'A replacement contract was issued.',
        ]);
    }

    /** @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private function snapshot(array $attributes): array
    {
        $booking = CarHireBooking::query()->findOrFail($attributes['car_hire_booking_id']);

        return [
            'schema_version' => 1,
            'booking_reference' => $booking->reference,
            'vehicle_name' => $booking->vehicle_name_snapshot,
            'registration_plate' => $booking->registration_plate_snapshot,
            'hire_mode' => $booking->hire_mode->value,
            'pickup_at' => $booking->pickup_at->toIso8601String(),
            'return_at' => $booking->return_at->toIso8601String(),
            'daily_rate_minor' => $booking->daily_rate_minor,
            'rental_subtotal_minor' => $booking->rental_subtotal_minor,
            'security_deposit_minor' => $booking->security_deposit_minor,
            'total_minor' => $booking->total_minor,
            'currency' => $booking->currency,
        ];
    }
}
