<?php

namespace Tests\Feature\CarHire;

use App\Enums\CarHireBookingEventType;
use App\Enums\CarHireBookingStatus;
use App\Enums\CarHireDocumentType;
use App\Enums\HireMode;
use App\Enums\SelfDriveApplicationStatus;
use App\Enums\VehicleCatalogueStatus;
use App\Enums\VehicleOperationalStatus;
use App\Models\CarHireBooking;
use App\Models\CarHireBookingEvent;
use App\Models\CarHireContract;
use App\Models\CarHireDocument;
use App\Models\CarHireDriverAssignment;
use App\Models\CarHireSelfDriveApplication;
use App\Models\Vehicle;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\CarHire\Concerns\BuildsCarHireFixtures;
use Tests\TestCase;

class CarHireDomainModelTest extends TestCase
{
    use BuildsCarHireFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-08-20 09:00:00');
    }

    public function test_vehicle_rate_and_booking_fields_are_cast_without_floating_point_money(): void
    {
        [$vehicle, $rate] = $this->bookableVehicle(rateAttributes: [
            'self_drive_daily_minor' => 345_678,
            'with_driver_daily_minor' => 456_789,
            'security_deposit_minor' => 123_456,
        ]);
        $booking = $this->persistedBooking(
            $this->customer(),
            $vehicle,
            $rate,
            mode: HireMode::SelfDrive,
        );

        $this->assertSame(VehicleCatalogueStatus::Published, $vehicle->catalogue_status);
        $this->assertSame(VehicleOperationalStatus::Available, $vehicle->operational_status);
        $this->assertIsInt($vehicle->seating_capacity);
        $this->assertTrue($vehicle->is_published_at ?? $vehicle->isPublishedAt());
        $this->assertIsInt($rate->self_drive_daily_minor);
        $this->assertSame(345_678, $rate->rateFor(HireMode::SelfDrive));
        $this->assertSame(CarHireBookingStatus::Pending, $booking->status);
        $this->assertSame(HireMode::SelfDrive, $booking->hire_mode);
        $this->assertIsInt($booking->daily_rate_minor);
        $this->assertIsInt($booking->rental_subtotal_minor);
        $this->assertIsInt($booking->security_deposit_minor);
        $this->assertIsInt($booking->total_minor);
        $this->assertSame('reference', $booking->getRouteKeyName());
        $this->assertSame('slug', $vehicle->getRouteKeyName());
    }

    public function test_aggregate_relationships_resolve_to_the_expected_records(): void
    {
        [$vehicle, $rate] = $this->bookableVehicle();
        $customer = $this->customer();
        $booking = $this->persistedBooking($customer, $vehicle, $rate, mode: HireMode::SelfDrive);
        $document = CarHireDocument::factory()->for($booking, 'booking')->nationalId()->create([
            'uploaded_by_user_id' => $customer->getKey(),
        ]);
        $contract = CarHireContract::factory()->for($booking, 'booking')->create();
        $assignment = CarHireDriverAssignment::factory()->for($booking, 'booking')->create();
        $event = CarHireBookingEvent::factory()->for($booking, 'booking')->create();

        $this->assertTrue($vehicle->hireRates()->whereKey($rate)->exists());
        $this->assertTrue($vehicle->media()->whereKey($vehicle->coverMedia)->exists());
        $this->assertTrue($vehicle->bookings()->whereKey($booking)->exists());
        $this->assertTrue($booking->customer->is($customer));
        $this->assertTrue($booking->vehicle->is($vehicle));
        $this->assertTrue($booking->hireRate->is($rate));
        $this->assertTrue($booking->selfDriveApplication->booking->is($booking));
        $this->assertTrue($booking->documents()->whereKey($document)->exists());
        $this->assertTrue($booking->contracts()->whereKey($contract)->exists());
        $this->assertTrue($booking->driverAssignments()->whereKey($assignment)->exists());
        $this->assertTrue($booking->events()->whereKey($event)->exists());
    }

    public function test_sensitive_self_drive_identifiers_are_encrypted_at_rest(): void
    {
        [$vehicle, $rate] = $this->bookableVehicle();
        $booking = $this->persistedBooking(
            $this->customer(),
            $vehicle,
            $rate,
            mode: HireMode::SelfDrive,
        );
        $application = $booking->selfDriveApplication;

        $application->update([
            'national_id_number' => 'CM90000001AA0A',
            'driving_permit_number' => 'UG-DP-123456789',
        ]);

        $raw = $application->getRawOriginal();
        $this->assertNotSame('CM90000001AA0A', $raw['national_id_number']);
        $this->assertNotSame('UG-DP-123456789', $raw['driving_permit_number']);
        $this->assertSame('CM90000001AA0A', $application->fresh()->national_id_number);
        $this->assertSame('UG-DP-123456789', $application->fresh()->driving_permit_number);
        $this->assertArrayNotHasKey('national_id_number', $application->toArray());
        $this->assertArrayNotHasKey('driving_permit_number', $application->toArray());
    }

    public function test_vehicle_and_driver_overlap_scopes_use_strict_half_open_intervals(): void
    {
        [$vehicle, $rate] = $this->bookableVehicle();
        $customer = $this->customer();
        $startsAt = now()->toImmutable()->addDays(5)->startOfHour();
        $endsAt = $startsAt->addDays(2);
        $booking = $this->persistedBooking(
            $customer,
            $vehicle,
            $rate,
            CarHireBookingStatus::Confirmed,
            attributes: [
                'pickup_at' => $startsAt,
                'return_at' => $endsAt,
            ],
        );
        $driver = $this->driver();
        CarHireDriverAssignment::factory()->for($booking, 'booking')->create([
            'driver_user_id' => $driver->getKey(),
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ]);

        $this->assertTrue(CarHireBooking::query()->overlapping($startsAt->subHour(), $startsAt->addHour())->exists());
        $this->assertFalse(CarHireBooking::query()->overlapping($endsAt, $endsAt->addDay())->exists());
        $this->assertFalse(CarHireBooking::query()->overlapping($startsAt->subDay(), $startsAt)->exists());
        $this->assertTrue(CarHireDriverAssignment::query()->forDriver($driver)
            ->overlapping($endsAt->subHour(), $endsAt->addHour())->exists());
        $this->assertFalse(CarHireDriverAssignment::query()->forDriver($driver)
            ->overlapping($endsAt, $endsAt->addHour())->exists());
    }

    public function test_expired_pending_holds_do_not_block_the_vehicle_but_confirmed_hires_do(): void
    {
        [$vehicle, $rate] = $this->bookableVehicle();
        $startsAt = now()->toImmutable()->addDays(4);
        $endsAt = $startsAt->addDays(2);
        $this->persistedBooking(
            $this->customer(),
            $vehicle,
            $rate,
            attributes: [
                'pickup_at' => $startsAt,
                'return_at' => $endsAt,
                'hold_expires_at' => now()->subMinute(),
            ],
        );

        $this->assertTrue(Vehicle::query()->availableForInterval($startsAt, $endsAt)->whereKey($vehicle)->exists());

        $this->persistedBooking(
            $this->customer(),
            $vehicle,
            $rate,
            CarHireBookingStatus::Confirmed,
            attributes: [
                'pickup_at' => $startsAt,
                'return_at' => $endsAt,
            ],
        );

        $this->assertFalse(Vehicle::query()->availableForInterval($startsAt, $endsAt)->whereKey($vehicle)->exists());
        $this->assertTrue(Vehicle::query()->availableForInterval($endsAt, $endsAt->addDay())->whereKey($vehicle)->exists());
    }

    public function test_database_constraints_prevent_duplicate_application_document_contract_and_event_versions(): void
    {
        [$vehicle, $rate] = $this->bookableVehicle();
        $booking = $this->persistedBooking(
            $this->customer(),
            $vehicle,
            $rate,
            mode: HireMode::SelfDrive,
        );

        $duplicateAssertions = [
            fn () => CarHireSelfDriveApplication::factory()->for($booking, 'booking')->create(),
            function () use ($booking): void {
                CarHireDocument::factory()->for($booking, 'booking')->nationalId()->create();
                CarHireDocument::factory()->for($booking, 'booking')->nationalId()->create();
            },
            function () use ($booking): void {
                CarHireContract::factory()->for($booking, 'booking')->create(['version' => 1]);
                CarHireContract::factory()->for($booking, 'booking')->create(['version' => 1]);
            },
            function () use ($booking): void {
                CarHireBookingEvent::factory()->for($booking, 'booking')->create([
                    'event_type' => CarHireBookingEventType::ReturnReminderSent,
                ]);
                CarHireBookingEvent::factory()->for($booking, 'booking')->create([
                    'event_type' => CarHireBookingEventType::ReturnReminderSent,
                ]);
            },
        ];

        foreach ($duplicateAssertions as $assertion) {
            try {
                $assertion();
                $this->fail('A protected aggregate uniqueness constraint accepted a duplicate.');
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        }

        $this->assertSame(CarHireDocumentType::NationalId, CarHireDocument::query()->first()->document_type);
        $this->assertSame(SelfDriveApplicationStatus::Draft, $booking->selfDriveApplication->status);
    }
}
