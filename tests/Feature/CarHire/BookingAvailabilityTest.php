<?php

namespace Tests\Feature\CarHire;

use App\Actions\CarHire\CreateCarHireBooking;
use App\Enums\CarHireBookingStatus;
use App\Enums\HireMode;
use App\Enums\VehicleCatalogueStatus;
use App\Enums\VehicleOperationalStatus;
use App\Models\CarHireBooking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\CarHire\Concerns\BuildsCarHireFixtures;
use Tests\TestCase;

class BookingAvailabilityTest extends TestCase
{
    use BuildsCarHireFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-08-20 09:00:00');
        Notification::fake();
    }

    /** @return array<string, array{CarHireBookingStatus}> */
    public static function holdingStatuses(): array
    {
        return [
            'unexpired pending' => [CarHireBookingStatus::Pending],
            'confirmed' => [CarHireBookingStatus::Confirmed],
            'in progress' => [CarHireBookingStatus::InProgress],
        ];
    }

    #[DataProvider('holdingStatuses')]
    public function test_holding_booking_statuses_reject_strict_interval_overlap(CarHireBookingStatus $status): void
    {
        [$vehicle, $rate] = $this->bookableVehicle();
        $pickup = now()->toImmutable()->addDays(10)->startOfHour();
        $return = $pickup->addDays(3);
        $this->persistedBooking(
            $this->customer(),
            $vehicle,
            $rate,
            $status,
            attributes: [
                'pickup_at' => $pickup,
                'return_at' => $return,
                'hold_expires_at' => now()->addDay(),
            ],
        );
        $customer = $this->customer();

        $this->assertTrue(
            CarHireBooking::query()
                ->forVehicle($vehicle)
                ->holdingVehicle()
                ->overlapping($pickup->addHour(), $return->addHour())
                ->exists(),
            'The fixture itself must hold this vehicle for the overlapping interval.',
        );

        try {
            app(CreateCarHireBooking::class)->execute(
                $customer,
                $vehicle,
                $this->bookingPayload($customer, overrides: [
                    'pickup_at' => $pickup->addHour()->setTimezone('Africa/Kampala')->format('Y-m-d H:i'),
                    'return_at' => $return->addHour()->setTimezone('Africa/Kampala')->format('Y-m-d H:i'),
                ]),
                (string) Str::uuid(),
            );
            $this->fail("An overlapping {$status->value} booking was accepted.");
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('pickup_at', $exception->errors());
        }

        $this->assertDatabaseCount('car_hire_bookings', 1);
    }

    /** @return array<string, array{CarHireBookingStatus, bool}> */
    public static function releasingStatuses(): array
    {
        return [
            'expired pending hold' => [CarHireBookingStatus::Pending, true],
            'cancelled' => [CarHireBookingStatus::Cancelled, false],
            'declined' => [CarHireBookingStatus::Declined, false],
            'expired' => [CarHireBookingStatus::Expired, false],
            'completed' => [CarHireBookingStatus::Completed, false],
        ];
    }

    #[DataProvider('releasingStatuses')]
    public function test_non_holding_statuses_release_the_vehicle(CarHireBookingStatus $status, bool $expireHold): void
    {
        [$vehicle, $rate] = $this->bookableVehicle();
        $pickup = now()->toImmutable()->addDays(10)->startOfHour();
        $return = $pickup->addDays(3);
        $this->persistedBooking(
            $this->customer(),
            $vehicle,
            $rate,
            $status,
            attributes: [
                'pickup_at' => $pickup,
                'return_at' => $return,
                'hold_expires_at' => $expireHold ? now()->subMinute() : now()->addDay(),
            ],
        );
        $customer = $this->customer();

        $booking = app(CreateCarHireBooking::class)->execute(
            $customer,
            $vehicle,
            $this->bookingPayload($customer, overrides: [
                'pickup_at' => $pickup->addHour()->setTimezone('Africa/Kampala')->format('Y-m-d H:i'),
                'return_at' => $return->addHour()->setTimezone('Africa/Kampala')->format('Y-m-d H:i'),
            ]),
            (string) Str::uuid(),
        );

        $this->assertSame(CarHireBookingStatus::Pending, $booking->status);
        $this->assertDatabaseCount('car_hire_bookings', 2);
    }

    public function test_return_at_existing_boundary_is_available_under_half_open_interval_rule(): void
    {
        [$vehicle, $rate] = $this->bookableVehicle();
        $pickup = now()->toImmutable()->addDays(10)->startOfHour();
        $return = $pickup->addDays(2);
        $this->persistedBooking(
            $this->customer(),
            $vehicle,
            $rate,
            CarHireBookingStatus::Confirmed,
            attributes: ['pickup_at' => $pickup, 'return_at' => $return],
        );
        $customer = $this->customer();

        $booking = app(CreateCarHireBooking::class)->execute(
            $customer,
            $vehicle,
            $this->bookingPayload($customer, overrides: [
                'pickup_at' => $return->setTimezone('Africa/Kampala')->format('Y-m-d H:i'),
                'return_at' => $return->addDay()->setTimezone('Africa/Kampala')->format('Y-m-d H:i'),
            ]),
            (string) Str::uuid(),
        );

        $this->assertTrue($booking->pickup_at->equalTo($return));
    }

    /** @return array<string, array{string}> */
    public static function unavailableCases(): array
    {
        return [
            'draft vehicle' => ['draft'],
            'future publication' => ['future-publication'],
            'maintenance vehicle' => ['maintenance'],
            'unsupported mode' => ['unsupported-mode'],
            'inactive rate' => ['inactive-rate'],
            'future rate' => ['future-rate'],
            'rate gap' => ['rate-gap'],
        ];
    }

    #[DataProvider('unavailableCases')]
    public function test_unavailable_vehicle_or_non_covering_rate_is_rejected(string $case): void
    {
        [$vehicle, $rate] = $this->bookableVehicle();
        $customer = $this->customer();
        $mode = HireMode::WithDriver;

        match ($case) {
            'draft' => $vehicle->update([
                'catalogue_status' => VehicleCatalogueStatus::Draft,
                'published_at' => null,
            ]),
            'future-publication' => $vehicle->update(['published_at' => now()->addDay()]),
            'maintenance' => $vehicle->update(['operational_status' => VehicleOperationalStatus::Maintenance]),
            'unsupported-mode' => [$rate->update(['self_drive_daily_minor' => null]), $mode = HireMode::SelfDrive],
            'inactive-rate' => $rate->update(['is_active' => false]),
            'future-rate' => $rate->update(['effective_from' => now()->addMonth()]),
            'rate-gap' => $rate->update(['effective_until' => now()->addDays(11)]),
        };

        try {
            app(CreateCarHireBooking::class)->execute(
                $customer,
                $vehicle->fresh(),
                $this->bookingPayload($customer, $mode),
                (string) Str::uuid(),
            );
            $this->fail("The {$case} case accepted a booking.");
        } catch (ValidationException $exception) {
            $this->assertNotEmpty(array_intersect(['vehicle', 'hire_mode'], array_keys($exception->errors())));
        }

        $this->assertDatabaseCount('car_hire_bookings', 0);
        $this->assertDatabaseCount('car_hire_contracts', 0);
    }

    public function test_minimum_notice_maximum_duration_and_ceil_day_billing_are_enforced(): void
    {
        [$vehicle] = $this->bookableVehicle();
        $customer = $this->customer();
        $cases = [
            [
                'pickup_at' => now()->addHour()->setTimezone('Africa/Kampala')->format('Y-m-d H:i'),
                'return_at' => now()->addDay()->setTimezone('Africa/Kampala')->format('Y-m-d H:i'),
                'field' => 'pickup_at',
            ],
            [
                'pickup_at' => now()->addDays(2)->setTimezone('Africa/Kampala')->format('Y-m-d H:i'),
                'return_at' => now()->addDays(93)->setTimezone('Africa/Kampala')->format('Y-m-d H:i'),
                'field' => 'return_at',
            ],
        ];

        foreach ($cases as $index => $case) {
            try {
                app(CreateCarHireBooking::class)->execute(
                    $customer,
                    $vehicle,
                    $this->bookingPayload($customer, overrides: $case),
                    (string) Str::uuid(),
                );
                $this->fail('An invalid hire interval was accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey($case['field'], $exception->errors(), "Interval case {$index} failed incorrectly.");
            }
        }

        $this->assertDatabaseCount('car_hire_bookings', 0);
    }
}
