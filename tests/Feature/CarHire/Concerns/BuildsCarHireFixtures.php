<?php

namespace Tests\Feature\CarHire\Concerns;

use App\Enums\AccountStatus;
use App\Enums\CarHireBookingStatus;
use App\Enums\HireMode;
use App\Enums\SelfDriveApplicationStatus;
use App\Enums\UserRole;
use App\Models\CarHireBooking;
use App\Models\CarHireContract;
use App\Models\CarHireSelfDriveApplication;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleHireRate;
use App\Models\VehicleMedia;
use Illuminate\Support\Str;

trait BuildsCarHireFixtures
{
    protected function customer(array $attributes = []): User
    {
        return $this->user(UserRole::Customer, $attributes);
    }

    protected function operationsUser(UserRole $role = UserRole::Staff, array $attributes = []): User
    {
        return $this->user($role, $attributes);
    }

    protected function driver(array $attributes = []): User
    {
        return $this->user(UserRole::Driver, $attributes);
    }

    protected function user(UserRole $role, array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'role' => $role,
            'status' => AccountStatus::Active,
            'email_verified_at' => now(),
            'phone' => '+256700'.fake()->unique()->numerify('######'),
        ], $attributes));
    }

    protected function publishedVehicle(array $attributes = []): Vehicle
    {
        $vehicle = Vehicle::factory()->published()->create(array_merge([
            'make' => 'Toyota',
            'model' => 'Land Cruiser',
            'year' => 2024,
            'registration_plate' => 'UBK '.fake()->unique()->numerify('###').'Z',
            'vehicle_type' => 'suv',
            'seating_capacity' => 7,
            'luggage_capacity' => 5,
        ], $attributes));

        VehicleMedia::factory()->cover()->for($vehicle)->create([
            'url' => '/storage/vehicles/land-cruiser.jpg',
            'alt_text' => 'Toyota Land Cruiser available for hire',
        ]);

        return $vehicle->fresh('media');
    }

    protected function currentRate(?Vehicle $vehicle = null, array $attributes = []): VehicleHireRate
    {
        $vehicle ??= $this->publishedVehicle();

        return VehicleHireRate::factory()->for($vehicle)->create(array_merge([
            'currency' => 'UGX',
            'self_drive_daily_minor' => 300_000,
            'with_driver_daily_minor' => 450_000,
            'security_deposit_minor' => 500_000,
            'effective_from' => now()->subMonth(),
            'effective_until' => null,
            'is_active' => true,
        ], $attributes));
    }

    /** @return array{0: Vehicle, 1: VehicleHireRate} */
    protected function bookableVehicle(
        array $vehicleAttributes = [],
        array $rateAttributes = [],
    ): array {
        $vehicle = $this->publishedVehicle($vehicleAttributes);

        return [$vehicle, $this->currentRate($vehicle, $rateAttributes)];
    }

    /** @return array<string, mixed> */
    protected function bookingPayload(
        User $customer,
        HireMode $mode = HireMode::WithDriver,
        array $overrides = [],
    ): array {
        return array_merge([
            'hire_mode' => $mode->value,
            'pickup_at' => now()->addDays(10)->setTimezone('Africa/Kampala')->format('Y-m-d H:i'),
            'return_at' => now()->addDays(13)->setTimezone('Africa/Kampala')->format('Y-m-d H:i'),
            'currency' => 'UGX',
            'pickup_location' => 'PISFA office, Kampala',
            'return_location' => 'Entebbe International Airport',
            'contact_phone' => $customer->phone,
            'special_requests' => 'A child seat, if available.',
            'acknowledge_request' => '1',
        ], $overrides);
    }

    protected function persistedBooking(
        User $customer,
        Vehicle $vehicle,
        ?VehicleHireRate $rate = null,
        CarHireBookingStatus $status = CarHireBookingStatus::Pending,
        HireMode $mode = HireMode::WithDriver,
        array $attributes = [],
        bool $withSelfDriveApplication = true,
    ): CarHireBooking {
        $rate ??= $this->currentRate($vehicle);
        $pickupAt = now()->toImmutable()->addDays(10)->startOfHour();
        $returnAt = $pickupAt->addDays(3);
        $timestamps = match ($status) {
            CarHireBookingStatus::Pending => [],
            CarHireBookingStatus::Confirmed => ['confirmed_at' => now()->subHour()],
            CarHireBookingStatus::InProgress => [
                'pickup_at' => now()->subDay(),
                'return_at' => now()->addDays(2),
                'confirmed_at' => now()->subDays(2),
                'in_progress_at' => now()->subDay(),
            ],
            CarHireBookingStatus::Completed => [
                'pickup_at' => now()->subDays(4),
                'return_at' => now()->subDay(),
                'confirmed_at' => now()->subDays(5),
                'in_progress_at' => now()->subDays(4),
                'completed_at' => now()->subDay(),
            ],
            CarHireBookingStatus::Cancelled => [
                'cancelled_at' => now()->subHour(),
                'cancellation_reason' => 'Cancelled for testing.',
            ],
            CarHireBookingStatus::Declined => [
                'cancellation_reason' => 'Declined for testing.',
            ],
            CarHireBookingStatus::Expired => [
                'hold_expires_at' => now()->subMinute(),
            ],
        };

        $booking = CarHireBooking::factory()->create(array_merge([
            'customer_id' => $customer->getKey(),
            'vehicle_id' => $vehicle->getKey(),
            'vehicle_hire_rate_id' => $rate->getKey(),
            'idempotency_key' => (string) Str::uuid(),
            'status' => $status,
            'hire_mode' => $mode,
            'pickup_at' => $pickupAt,
            'return_at' => $returnAt,
            'cancellation_cutoff_at' => $pickupAt->subHours(48),
            'hold_expires_at' => now()->addDay(),
            'vehicle_name_snapshot' => "{$vehicle->year} {$vehicle->make} {$vehicle->model}",
            'registration_plate_snapshot' => $vehicle->registration_plate,
            'currency' => $rate->currency,
            'daily_rate_minor' => $rate->rateFor($mode),
            'security_deposit_minor' => $rate->security_deposit_minor,
        ], $timestamps, $attributes));

        if ($mode === HireMode::SelfDrive
            && $withSelfDriveApplication
            && ! $booking->selfDriveApplication()->exists()) {
            CarHireSelfDriveApplication::factory()->for($booking, 'booking')->create([
                'status' => SelfDriveApplicationStatus::Draft,
            ]);
        }

        return $booking->fresh([
            'customer',
            'vehicle.coverMedia',
            'hireRate',
            'selfDriveApplication',
            'contracts',
        ]);
    }

    protected function contractFor(CarHireBooking $booking, bool $accepted = false): CarHireContract
    {
        $snapshot = [
            'schema_version' => 1,
            'booking_reference' => $booking->reference,
            'customer' => [
                'name' => $booking->contact_name,
                'email' => $booking->contact_email,
                'phone' => $booking->contact_phone,
            ],
            'vehicle' => [
                'name' => $booking->vehicle_name_snapshot,
                'registration_plate' => $booking->registration_plate_snapshot,
            ],
            'hire_mode' => $booking->hire_mode->value,
            'pickup_at' => $booking->pickup_at->toIso8601String(),
            'return_at' => $booking->return_at->toIso8601String(),
            'pickup_location' => $booking->pickup_location,
            'return_location' => $booking->return_location,
            'billable_days' => $booking->billable_days,
            'daily_rate_minor' => $booking->daily_rate_minor,
            'rental_subtotal_minor' => $booking->rental_subtotal_minor,
            'security_deposit_minor' => $booking->security_deposit_minor,
            'total_minor' => $booking->total_minor,
            'currency' => $booking->currency,
        ];
        $terms = 'Booking-specific car-hire terms used by the automated test fixture.';

        return CarHireContract::query()->create([
            'car_hire_booking_id' => $booking->getKey(),
            'contract_number' => 'HC-'.Str::upper((string) Str::ulid()),
            'version' => (int) $booking->contracts()->max('version') + 1,
            'template_version' => (string) config('car_hire.contract.version', '2026-08-20'),
            'snapshot' => $snapshot,
            'terms_snapshot' => $terms,
            'content_sha256' => $this->expectedContractHash($snapshot, $terms),
            'issued_at' => now(),
            'accepted_at' => $accepted ? now() : null,
            'accepted_by_user_id' => $accepted ? $booking->customer_id : null,
            'acceptance_ip' => $accepted ? '127.0.0.1' : null,
            'acceptance_user_agent' => $accepted ? 'PISFA automated test' : null,
        ]);
    }

    /** @param array<string, mixed> $snapshot */
    protected function expectedContractHash(array $snapshot, string $terms): string
    {
        $canonicalize = function (mixed $value) use (&$canonicalize): mixed {
            if (! is_array($value)) {
                return $value;
            }

            if (! array_is_list($value)) {
                ksort($value, SORT_STRING);
            }

            foreach ($value as $key => $nested) {
                $value[$key] = $canonicalize($nested);
            }

            return $value;
        };
        $encoded = json_encode(
            $canonicalize($snapshot),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );

        return hash('sha256', $encoded."\n".$terms);
    }
}
