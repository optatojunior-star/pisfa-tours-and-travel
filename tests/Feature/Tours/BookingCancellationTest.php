<?php

namespace Tests\Feature\Tours;

use App\Actions\Tours\CancelTourBooking;
use App\Actions\Tours\CreateTourBooking;
use App\Enums\AccountStatus;
use App\Enums\TourBookingStatus;
use App\Enums\UserRole;
use App\Models\TourAssignment;
use App\Notifications\Tours\TourBookingCancelledNotification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Tours\Concerns\BuildsTourFixtures;
use Tests\TestCase;

class BookingCancellationTest extends TestCase
{
    use BuildsTourFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-08-20 09:00:00');
    }

    public function test_owner_can_cancel_before_cutoff_and_capacity_is_released_atomically(): void
    {
        Notification::fake();

        $owner = $this->customer();
        $replacementCustomer = $this->customer();
        $driver = $this->driver();
        $departure = $this->bookableDeparture(attributes: ['capacity' => 2]);
        $booking = $this->persistedBooking(
            $owner,
            $departure,
            TourBookingStatus::Confirmed,
            2,
            ['assigned_driver_user_id' => $driver->getKey()],
        );
        $assignment = TourAssignment::factory()
            ->for($booking, 'booking')
            ->create([
                'driver_user_id' => $driver->getKey(),
                'starts_at' => $booking->departure_starts_at_snapshot,
                'ends_at' => $booking->departure_ends_at_snapshot,
            ]);

        $cancelled = app(CancelTourBooking::class)->execute($owner, $booking, 'Family plans changed.');

        $this->assertSame(TourBookingStatus::Cancelled, $cancelled->status);
        $this->assertSame('Family plans changed.', $cancelled->cancellation_reason);
        $this->assertSame($owner->getKey(), $cancelled->cancelled_by_user_id);
        $this->assertNotNull($cancelled->cancelled_at);
        $this->assertNull($cancelled->assigned_driver_user_id);
        $this->assertNotNull($assignment->fresh()->unassigned_at);
        $this->assertSame($owner->getKey(), $assignment->fresh()->unassigned_by_user_id);
        $this->assertSame(0, (int) $departure->bookings()->holdingCapacity()->sum('traveler_count'));

        $replacement = app(CreateTourBooking::class)->execute(
            $replacementCustomer,
            $departure,
            $this->bookingPayload($replacementCustomer, 2),
            'replacement-after-cancel-001',
        );

        $this->assertSame(2, $replacement->traveler_count);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'tour_booking.cancelled',
            'auditable_id' => $booking->getKey(),
            'user_id' => $owner->getKey(),
        ]);
        Notification::assertSentToTimes($owner, TourBookingCancelledNotification::class, 1);
    }

    public function test_customer_direct_cancellation_rejects_null_and_blank_reasons(): void
    {
        Notification::fake();

        $owner = $this->customer();
        $booking = $this->persistedBooking($owner, $this->bookableDeparture());

        foreach ([null, '   '] as $reason) {
            try {
                app(CancelTourBooking::class)->execute($owner, $booking, $reason);
                $this->fail('A customer directly cancelled without a meaningful reason.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('reason', $exception->errors());
                $this->assertSame(TourBookingStatus::Pending, $booking->fresh()->status);
            }
        }

        $this->assertDatabaseCount('audit_logs', 0);
        Notification::assertNothingSent();
    }

    public function test_another_customer_and_a_driver_cannot_cancel_the_booking(): void
    {
        $owner = $this->customer();
        $booking = $this->persistedBooking($owner, $this->bookableDeparture());

        foreach ([$this->customer(), $this->driver()] as $unauthorizedActor) {
            try {
                app(CancelTourBooking::class)->execute($unauthorizedActor, $booking, 'Not my booking.');
                $this->fail('A non-owner cancelled the booking.');
            } catch (AuthorizationException) {
                $this->assertSame(TourBookingStatus::Pending, $booking->fresh()->status);
            }
        }

        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_customer_cannot_cancel_after_the_snapshot_cutoff(): void
    {
        $owner = $this->customer();
        $booking = $this->persistedBooking(
            $owner,
            $this->bookableDeparture(),
            attributes: ['cancellation_cutoff_at_snapshot' => now()->subMinute()],
        );

        try {
            app(CancelTourBooking::class)->execute($owner, $booking, 'Too late.');
            $this->fail('A customer cancelled after the cutoff.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('booking', $exception->errors());
            $this->assertSame(TourBookingStatus::Pending, $booking->fresh()->status);
        }
    }

    public function test_operations_can_cancel_after_cutoff_but_must_supply_a_customer_facing_reason(): void
    {
        Notification::fake();

        $staff = $this->operationsUser();
        $owner = $this->customer();
        $booking = $this->persistedBooking(
            $owner,
            $this->bookableDeparture(),
            attributes: ['cancellation_cutoff_at_snapshot' => now()->subMinute()],
        );

        try {
            app(CancelTourBooking::class)->execute($staff, $booking);
            $this->fail('Operations cancelled without a reason.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('reason', $exception->errors());
        }

        $cancelled = app(CancelTourBooking::class)->execute(
            $staff,
            $booking,
            'Departure cannot operate safely.',
        );

        $this->assertSame(TourBookingStatus::Cancelled, $cancelled->status);
        $this->assertSame($staff->getKey(), $cancelled->cancelled_by_user_id);
        Notification::assertSentToTimes($owner, TourBookingCancelledNotification::class, 1);
    }

    /** @return array<string, array{TourBookingStatus}> */
    public static function terminalOrStartedStatuses(): array
    {
        return [
            'in progress' => [TourBookingStatus::InProgress],
            'completed' => [TourBookingStatus::Completed],
        ];
    }

    #[DataProvider('terminalOrStartedStatuses')]
    public function test_started_or_completed_bookings_cannot_be_cancelled(TourBookingStatus $status): void
    {
        $staff = $this->operationsUser();
        $owner = $this->customer();
        $booking = $this->persistedBooking($owner, $this->bookableDeparture(), $status);

        try {
            app(CancelTourBooking::class)->execute($staff, $booking, 'Attempted cancellation.');
            $this->fail('An immutable booking status was cancelled.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
            $this->assertSame($status, $booking->fresh()->status);
        }
    }

    public function test_repeated_cancellation_is_idempotent_without_duplicate_audit_or_notification(): void
    {
        Notification::fake();

        $owner = $this->customer();
        $booking = $this->persistedBooking($owner, $this->bookableDeparture());
        $action = app(CancelTourBooking::class);

        $first = $action->execute($owner, $booking, 'Plans changed.');
        $second = $action->execute($owner, $booking, 'Plans changed.');

        $this->assertTrue($first->is($second));
        $this->assertSame(
            1,
            DB::table('audit_logs')->where('event', 'tour_booking.cancelled')->count(),
        );
        Notification::assertSentToTimes($owner, TourBookingCancelledNotification::class, 1);
    }

    public function test_inactive_operations_account_is_denied(): void
    {
        $staff = $this->operationsUser(UserRole::Manager, ['status' => AccountStatus::Inactive]);
        $booking = $this->persistedBooking($this->customer(), $this->bookableDeparture());

        $this->expectException(AuthorizationException::class);

        app(CancelTourBooking::class)->execute($staff, $booking, 'Operational reason.');
    }
}
