<?php

namespace Tests\Feature\CarHire;

use App\Actions\CarHire\AssignCarHireDriver;
use App\Enums\AccountStatus;
use App\Enums\CarHireBookingStatus;
use App\Enums\HireMode;
use App\Models\CarHireDriverAssignment;
use App\Models\TourAssignment;
use App\Notifications\CarHire\CarHireDriverAssignmentNotification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\Feature\CarHire\Concerns\BuildsCarHireFixtures;
use Tests\TestCase;

class DriverAssignmentTest extends TestCase
{
    use BuildsCarHireFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-08-20 09:00:00');
        Notification::fake();
    }

    public function test_operations_assigns_verified_driver_idempotently_with_history_and_notifications(): void
    {
        [$vehicle, $rate] = $this->bookableVehicle();
        $customer = $this->customer();
        $booking = $this->persistedBooking($customer, $vehicle, $rate, CarHireBookingStatus::Confirmed);
        $driver = $this->driver();
        $actor = $this->operationsUser();
        $action = app(AssignCarHireDriver::class);

        $assigned = $action->execute($actor, $booking, $driver);
        $replay = $action->execute($actor, $assigned, $driver);

        $this->assertTrue($assigned->is($replay));
        $this->assertSame($driver->getKey(), $assigned->assigned_driver_user_id);
        $assignment = $assigned->driverAssignments->sole();
        $this->assertSame($driver->getKey(), $assignment->driver_user_id);
        $this->assertTrue($assignment->starts_at->equalTo($booking->pickup_at));
        $this->assertTrue($assignment->ends_at->equalTo($booking->return_at));
        $this->assertNull($assignment->unassigned_at);
        $this->assertDatabaseCount('car_hire_driver_assignments', 1);
        Notification::assertSentToTimes($customer, CarHireDriverAssignmentNotification::class, 1);
        Notification::assertSentToTimes($driver, CarHireDriverAssignmentNotification::class, 1);
    }

    public function test_reassignment_closes_history_and_unassignment_requires_reason(): void
    {
        [$vehicle, $rate] = $this->bookableVehicle();
        $booking = $this->persistedBooking(
            $this->customer(),
            $vehicle,
            $rate,
            CarHireBookingStatus::Confirmed,
        );
        $actor = $this->operationsUser();
        $firstDriver = $this->driver();
        $secondDriver = $this->driver();
        $action = app(AssignCarHireDriver::class);
        $action->execute($actor, $booking, $firstDriver);
        $reassigned = $action->execute($actor, $booking->fresh(), $secondDriver, 'Driver became unavailable.');

        $this->assertSame(2, $booking->driverAssignments()->count());
        $this->assertSame(1, $booking->driverAssignments()->active()->count());
        $this->assertSame($secondDriver->getKey(), $reassigned->assigned_driver_user_id);
        $this->assertSame(
            'Driver became unavailable.',
            $booking->driverAssignments()->reorder()->oldest('id')->first()->unassignment_reason,
        );

        try {
            $action->execute($actor, $reassigned, null);
            $this->fail('A driver was removed without a reason.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('unassignment_reason', $exception->errors());
        }

        $released = $action->execute($actor, $reassigned, null, 'Customer changed to another arrangement.');
        $this->assertNull($released->assigned_driver_user_id);
        $this->assertSame(0, $booking->driverAssignments()->active()->count());
    }

    public function test_self_drive_wrong_status_and_invalid_driver_accounts_are_rejected(): void
    {
        [$vehicle, $rate] = $this->bookableVehicle();
        $actor = $this->operationsUser();
        $driver = $this->driver();
        $cases = [
            $this->persistedBooking(
                $this->customer(),
                $vehicle,
                $rate,
                CarHireBookingStatus::Confirmed,
                HireMode::SelfDrive,
            ),
            $this->persistedBooking($this->customer(), $vehicle, $rate, CarHireBookingStatus::Pending),
        ];

        foreach ($cases as $booking) {
            try {
                app(AssignCarHireDriver::class)->execute($actor, $booking, $driver);
                $this->fail('An ineligible booking received a driver.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('driver', $exception->errors());
            }
        }

        $eligible = $this->persistedBooking(
            $this->customer(),
            $vehicle,
            $rate,
            CarHireBookingStatus::Confirmed,
            attributes: [
                'pickup_at' => now()->addDays(20),
                'return_at' => now()->addDays(22),
            ],
        );
        foreach ([
            $this->driver(['status' => AccountStatus::Inactive]),
            $this->driver(['email_verified_at' => null]),
            $this->customer(),
        ] as $invalidDriver) {
            try {
                app(AssignCarHireDriver::class)->execute($actor, $eligible, $invalidDriver);
                $this->fail('An invalid driver account was assigned.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('driver', $exception->errors());
            }
        }
    }

    public function test_car_hire_driver_overlap_is_strict_and_boundary_is_allowed(): void
    {
        [$firstVehicle, $firstRate] = $this->bookableVehicle();
        [$secondVehicle, $secondRate] = $this->bookableVehicle();
        $startsAt = now()->toImmutable()->addDays(10)->startOfHour();
        $endsAt = $startsAt->addDays(2);
        $driver = $this->driver();
        $existing = $this->persistedBooking(
            $this->customer(),
            $firstVehicle,
            $firstRate,
            CarHireBookingStatus::Confirmed,
            attributes: ['pickup_at' => $startsAt, 'return_at' => $endsAt],
        );
        CarHireDriverAssignment::factory()->for($existing, 'booking')->create([
            'driver_user_id' => $driver->getKey(),
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ]);
        $overlap = $this->persistedBooking(
            $this->customer(),
            $secondVehicle,
            $secondRate,
            CarHireBookingStatus::Confirmed,
            attributes: ['pickup_at' => $endsAt->subHour(), 'return_at' => $endsAt->addDay()],
        );

        try {
            app(AssignCarHireDriver::class)->execute($this->operationsUser(), $overlap, $driver);
            $this->fail('An overlapping vehicle-hire driver assignment succeeded.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('driver', $exception->errors());
        }

        $overlap->update(['pickup_at' => $endsAt, 'return_at' => $endsAt->addDay()]);
        $assigned = app(AssignCarHireDriver::class)->execute(
            $this->operationsUser(),
            $overlap->fresh(),
            $driver,
        );
        $this->assertSame($driver->getKey(), $assigned->assigned_driver_user_id);
    }

    public function test_active_tour_assignment_blocks_cross_domain_driver_overlap(): void
    {
        [$vehicle, $rate] = $this->bookableVehicle();
        $startsAt = now()->toImmutable()->addDays(10)->startOfHour();
        $endsAt = $startsAt->addDays(2);
        $driver = $this->driver();
        TourAssignment::factory()->create([
            'driver_user_id' => $driver->getKey(),
            'starts_at' => $startsAt->subHour(),
            'ends_at' => $startsAt->addHour(),
        ]);
        $booking = $this->persistedBooking(
            $this->customer(),
            $vehicle,
            $rate,
            CarHireBookingStatus::Confirmed,
            attributes: ['pickup_at' => $startsAt, 'return_at' => $endsAt],
        );

        try {
            app(AssignCarHireDriver::class)->execute($this->operationsUser(), $booking, $driver);
            $this->fail('A cross-domain overlapping driver assignment succeeded.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('driver', $exception->errors());
        }
    }

    public function test_non_operations_actor_cannot_assign_driver(): void
    {
        [$vehicle, $rate] = $this->bookableVehicle();
        $booking = $this->persistedBooking(
            $this->customer(),
            $vehicle,
            $rate,
            CarHireBookingStatus::Confirmed,
        );

        $this->expectException(AuthorizationException::class);
        app(AssignCarHireDriver::class)->execute($this->customer(), $booking, $this->driver());
    }
}
