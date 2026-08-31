<?php

namespace Tests\Feature\Tours;

use App\Actions\Tours\AssignTourDriver;
use App\Enums\AccountStatus;
use App\Enums\TourBookingStatus;
use App\Enums\UserRole;
use App\Models\TourAssignment;
use App\Notifications\Tours\TourDriverAssignedNotification;
use App\Notifications\Tours\TourDriverUnassignedNotification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Tours\Concerns\BuildsTourFixtures;
use Tests\TestCase;

class DriverAssignmentTest extends TestCase
{
    use BuildsTourFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-08-20 09:00:00');
    }

    public function test_active_verified_driver_is_assigned_with_history_audit_and_notifications(): void
    {
        Notification::fake();

        $staff = $this->operationsUser();
        $customer = $this->customer();
        $driver = $this->driver();
        $booking = $this->persistedBooking(
            $customer,
            $this->bookableDeparture(),
            TourBookingStatus::Confirmed,
        );
        $action = app(AssignTourDriver::class);

        $assigned = $action->execute($staff, $booking, $driver);
        $replayed = $action->execute($staff, $booking, $driver);

        $this->assertTrue($assigned->is($replayed));
        $this->assertSame($driver->getKey(), $assigned->assigned_driver_user_id);
        $this->assertDatabaseCount('tour_assignments', 1);

        $history = TourAssignment::query()->sole();
        $this->assertSame($booking->getKey(), $history->tour_booking_id);
        $this->assertSame($driver->getKey(), $history->driver_user_id);
        $this->assertSame($staff->getKey(), $history->assigned_by_user_id);
        $this->assertTrue($history->starts_at->equalTo($booking->departure_starts_at_snapshot));
        $this->assertTrue($history->ends_at->equalTo($booking->departure_ends_at_snapshot));
        $this->assertNull($history->unassigned_at);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'tour_booking.driver_assigned',
            'auditable_id' => $booking->getKey(),
            'user_id' => $staff->getKey(),
        ]);
        $this->assertSame(
            1,
            DB::table('audit_logs')->where('event', 'tour_booking.driver_assigned')->count(),
        );
        Notification::assertSentToTimes($customer, TourDriverAssignedNotification::class, 1);
        Notification::assertSentToTimes($driver, TourDriverAssignedNotification::class, 1);
    }

    /** @return array<string, array{UserRole, AccountStatus, bool}> */
    public static function invalidDrivers(): array
    {
        return [
            'customer role' => [UserRole::Customer, AccountStatus::Active, true],
            'staff role' => [UserRole::Staff, AccountStatus::Active, true],
            'inactive driver' => [UserRole::Driver, AccountStatus::Inactive, true],
            'suspended driver' => [UserRole::Driver, AccountStatus::Suspended, true],
            'unverified driver' => [UserRole::Driver, AccountStatus::Active, false],
        ];
    }

    #[DataProvider('invalidDrivers')]
    public function test_assignment_requires_an_active_verified_driver_role(
        UserRole $role,
        AccountStatus $status,
        bool $verified,
    ): void {
        $candidate = $this->user($role, [
            'status' => $status,
            'email_verified_at' => $verified ? now() : null,
        ]);
        $booking = $this->persistedBooking(
            $this->customer(),
            $this->bookableDeparture(),
            TourBookingStatus::Confirmed,
        );

        try {
            app(AssignTourDriver::class)->execute($this->operationsUser(), $booking, $candidate);
            $this->fail('An invalid driver candidate was assigned.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('driver', $exception->errors());
            $this->assertNull($booking->fresh()->assigned_driver_user_id);
            $this->assertDatabaseCount('tour_assignments', 0);
        }
    }

    /** @return array<string, array{TourBookingStatus}> */
    public static function unassignableBookingStatuses(): array
    {
        return [
            'pending' => [TourBookingStatus::Pending],
            'completed' => [TourBookingStatus::Completed],
            'cancelled' => [TourBookingStatus::Cancelled],
        ];
    }

    #[DataProvider('unassignableBookingStatuses')]
    public function test_drivers_are_only_assigned_to_confirmed_or_in_progress_bookings(TourBookingStatus $status): void
    {
        $booking = $this->persistedBooking($this->customer(), $this->bookableDeparture(), $status);

        try {
            app(AssignTourDriver::class)->execute($this->operationsUser(), $booking, $this->driver());
            $this->fail('A driver was assigned to an invalid booking status.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('driver', $exception->errors());
            $this->assertDatabaseCount('tour_assignments', 0);
        }
    }

    public function test_strict_overlap_is_rejected_but_back_to_back_assignments_are_allowed(): void
    {
        $staff = $this->operationsUser();
        $driver = $this->driver();
        $package = $this->publishedTour();
        $firstStart = now()->toImmutable()->addDays(5)->startOfHour();
        $firstDeparture = $this->bookableDeparture($package, [
            'starts_at' => $firstStart,
            'ends_at' => $firstStart->addHours(4),
            'cancellation_cutoff_at' => $firstStart->subDay(),
        ]);
        $overlapStart = $firstStart->addHours(3);
        $overlapDeparture = $this->bookableDeparture($package, [
            'starts_at' => $overlapStart,
            'ends_at' => $overlapStart->addHours(3),
            'cancellation_cutoff_at' => $overlapStart->subDay(),
        ]);
        $backToBackStart = $firstStart->addHours(4);
        $backToBackDeparture = $this->bookableDeparture($package, [
            'starts_at' => $backToBackStart,
            'ends_at' => $backToBackStart->addHours(3),
            'cancellation_cutoff_at' => $backToBackStart->subDay(),
        ]);
        $first = $this->persistedBooking($this->customer(), $firstDeparture, TourBookingStatus::Confirmed);
        $overlap = $this->persistedBooking($this->customer(), $overlapDeparture, TourBookingStatus::Confirmed);
        $backToBack = $this->persistedBooking($this->customer(), $backToBackDeparture, TourBookingStatus::Confirmed);
        $action = app(AssignTourDriver::class);

        $action->execute($staff, $first, $driver);

        try {
            $action->execute($staff, $overlap, $driver);
            $this->fail('A strictly overlapping assignment succeeded.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('driver', $exception->errors());
            $this->assertNull($overlap->fresh()->assigned_driver_user_id);
        }

        $assignedBackToBack = $action->execute($staff, $backToBack, $driver);

        $this->assertSame($driver->getKey(), $assignedBackToBack->assigned_driver_user_id);
        $this->assertDatabaseCount('tour_assignments', 2);
    }

    public function test_reassignment_closes_old_history_and_notifies_old_and_new_drivers(): void
    {
        Notification::fake();

        $staff = $this->operationsUser();
        $customer = $this->customer();
        $oldDriver = $this->driver();
        $newDriver = $this->driver();
        $booking = $this->persistedBooking(
            $customer,
            $this->bookableDeparture(),
            TourBookingStatus::Confirmed,
        );
        $action = app(AssignTourDriver::class);

        $action->execute($staff, $booking, $oldDriver);
        $reassigned = $action->execute($staff, $booking, $newDriver, 'Roster change.');

        $this->assertSame($newDriver->getKey(), $reassigned->assigned_driver_user_id);
        $this->assertDatabaseCount('tour_assignments', 2);
        $this->assertSame(1, TourAssignment::query()->active()->count());

        $oldHistory = TourAssignment::query()->where('driver_user_id', $oldDriver->getKey())->sole();
        $this->assertNotNull($oldHistory->unassigned_at);
        $this->assertSame($staff->getKey(), $oldHistory->unassigned_by_user_id);
        $this->assertSame('Roster change.', $oldHistory->unassignment_reason);
        Notification::assertSentToTimes($oldDriver, TourDriverUnassignedNotification::class, 1);
        Notification::assertSentToTimes($newDriver, TourDriverAssignedNotification::class, 1);
        Notification::assertSentToTimes($customer, TourDriverAssignedNotification::class, 2);
    }

    public function test_unassignment_requires_a_reason_and_preserves_history(): void
    {
        Notification::fake();

        $staff = $this->operationsUser();
        $driver = $this->driver();
        $booking = $this->persistedBooking(
            $this->customer(),
            $this->bookableDeparture(),
            TourBookingStatus::Confirmed,
        );
        $action = app(AssignTourDriver::class);
        $action->execute($staff, $booking, $driver);

        try {
            $action->execute($staff, $booking, null);
            $this->fail('The driver was removed without a reason.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('unassignment_reason', $exception->errors());
            $this->assertSame($driver->getKey(), $booking->fresh()->assigned_driver_user_id);
        }

        $unassigned = $action->execute($staff, $booking, null, 'Driver became unavailable.');

        $this->assertNull($unassigned->assigned_driver_user_id);
        $this->assertDatabaseCount('tour_assignments', 1);
        $history = TourAssignment::query()->sole();
        $this->assertNotNull($history->unassigned_at);
        $this->assertSame('Driver became unavailable.', $history->unassignment_reason);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'tour_booking.driver_unassigned',
            'auditable_id' => $booking->getKey(),
        ]);
        Notification::assertSentToTimes($driver, TourDriverUnassignedNotification::class, 1);
    }

    public function test_departure_end_blocks_new_and_replacement_drivers_but_allows_reasoned_unassignment(): void
    {
        Notification::fake();

        $staff = $this->operationsUser();
        $oldDriver = $this->driver();
        $newDriver = $this->driver();
        $departure = $this->bookableDeparture();
        $unassignedBooking = $this->persistedBooking(
            $this->customer(),
            $departure,
            TourBookingStatus::Confirmed,
        );
        $assignedBooking = $this->persistedBooking(
            $this->customer(),
            $departure,
            TourBookingStatus::Confirmed,
        );
        $action = app(AssignTourDriver::class);
        $action->execute($staff, $assignedBooking, $oldDriver);
        $this->travelTo($assignedBooking->departure_ends_at_snapshot);

        foreach ([
            'new assignment' => $unassignedBooking,
            'replacement assignment' => $assignedBooking,
        ] as $case => $booking) {
            try {
                $action->execute($staff, $booking, $newDriver, 'Post-trip roster change.');
                $this->fail("A {$case} succeeded after the departure ended.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('driver', $exception->errors());
            }
        }

        $this->assertNull($unassignedBooking->fresh()->assigned_driver_user_id);
        $this->assertSame($oldDriver->getKey(), $assignedBooking->fresh()->assigned_driver_user_id);

        $unassigned = $action->execute(
            $staff,
            $assignedBooking,
            null,
            'Closing the completed trip roster.',
        );

        $this->assertNull($unassigned->assigned_driver_user_id);
        $this->assertDatabaseHas('tour_assignments', [
            'tour_booking_id' => $assignedBooking->getKey(),
            'driver_user_id' => $oldDriver->getKey(),
            'unassignment_reason' => 'Closing the completed trip roster.',
        ]);
        $this->assertNotNull(TourAssignment::query()->sole()->unassigned_at);
    }

    /** @return array<string, array{UserRole, AccountStatus}> */
    public static function unauthorizedAssigners(): array
    {
        return [
            'customer' => [UserRole::Customer, AccountStatus::Active],
            'driver' => [UserRole::Driver, AccountStatus::Active],
            'inactive staff' => [UserRole::Staff, AccountStatus::Inactive],
        ];
    }

    #[DataProvider('unauthorizedAssigners')]
    public function test_non_operations_or_inactive_accounts_cannot_assign_drivers(
        UserRole $role,
        AccountStatus $status,
    ): void {
        $actor = $this->user($role, ['status' => $status]);
        $booking = $this->persistedBooking(
            $this->customer(),
            $this->bookableDeparture(),
            TourBookingStatus::Confirmed,
        );

        $this->expectException(AuthorizationException::class);

        app(AssignTourDriver::class)->execute($actor, $booking, $this->driver());
    }
}
