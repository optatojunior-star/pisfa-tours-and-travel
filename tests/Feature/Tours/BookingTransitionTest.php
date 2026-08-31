<?php

namespace Tests\Feature\Tours;

use App\Actions\Tours\TransitionTourBooking;
use App\Enums\AccountStatus;
use App\Enums\TourBookingEventType;
use App\Enums\TourBookingStatus;
use App\Enums\UserRole;
use App\Models\TourAssignment;
use App\Models\TourBookingEvent;
use App\Models\TourTraveler;
use App\Notifications\Tours\TourBookingConfirmedNotification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Tours\Concerns\BuildsTourFixtures;
use Tests\TestCase;

class BookingTransitionTest extends TestCase
{
    use BuildsTourFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-08-20 09:00:00');
    }

    /** @return iterable<string, array{TourBookingStatus, TourBookingStatus, bool}> */
    public static function transitionMatrix(): iterable
    {
        foreach (TourBookingStatus::cases() as $from) {
            foreach (TourBookingStatus::cases() as $to) {
                yield "{$from->value} to {$to->value}" => [
                    $from,
                    $to,
                    $from === $to || $from->canTransitionTo($to),
                ];
            }
        }
    }

    #[DataProvider('transitionMatrix')]
    public function test_every_booking_status_transition_is_explicitly_allowed_or_rejected(
        TourBookingStatus $from,
        TourBookingStatus $to,
        bool $accepted,
    ): void {
        Notification::fake();

        $staff = $this->operationsUser();
        $owner = $this->customer();
        $needsEndedDeparture = $from === TourBookingStatus::Completed
            || ($from === TourBookingStatus::InProgress && $to === TourBookingStatus::Completed);
        $needsStartedDeparture = $from === TourBookingStatus::InProgress
            || ($from === TourBookingStatus::Confirmed && $to === TourBookingStatus::InProgress);
        $departure = $needsEndedDeparture
            ? $this->bookableDeparture(attributes: [
                'starts_at' => now()->subDays(2),
                'ends_at' => now()->subHour(),
                'cancellation_cutoff_at' => now()->subDays(3),
            ])
            : ($needsStartedDeparture
                ? $this->bookableDeparture(attributes: [
                    'starts_at' => now()->subDay(),
                    'ends_at' => now()->addDay(),
                    'cancellation_cutoff_at' => now()->subDays(2),
                ])
                : $this->bookableDeparture());
        $booking = $this->persistedBooking($owner, $departure, $from);

        try {
            $result = app(TransitionTourBooking::class)->execute(
                $staff,
                $booking,
                $to,
                'Required operational reason.',
            );

            if (! $accepted) {
                $this->fail("The illegal {$from->value} to {$to->value} transition succeeded.");
            }

            $this->assertSame($to, $result->status);

            if ($from !== $to) {
                $event = $to === TourBookingStatus::Cancelled
                    ? 'tour_booking.cancelled'
                    : 'tour_booking.status_changed';
                $this->assertDatabaseHas('audit_logs', [
                    'event' => $event,
                    'auditable_id' => $booking->getKey(),
                    'user_id' => $staff->getKey(),
                ]);
            }
        } catch (ValidationException $exception) {
            if ($accepted) {
                throw $exception;
            }

            $this->assertNotEmpty($exception->errors());
            $this->assertSame($from, $booking->fresh()->status);
        }
    }

    public function test_completion_records_one_unique_unprocessed_loyalty_event_even_when_replayed(): void
    {
        $staff = $this->operationsUser();
        $owner = $this->customer();
        $departure = $this->bookableDeparture(attributes: [
            'starts_at' => now()->subDays(2),
            'ends_at' => now()->subDay(),
            'cancellation_cutoff_at' => now()->subDays(3),
        ]);
        $booking = $this->persistedBooking($owner, $departure, TourBookingStatus::InProgress);
        $action = app(TransitionTourBooking::class);

        $completed = $action->execute($staff, $booking, TourBookingStatus::Completed);
        $replayed = $action->execute($staff, $booking, TourBookingStatus::Completed);

        $this->assertSame(TourBookingStatus::Completed, $completed->status);
        $this->assertTrue($completed->is($replayed));
        $this->assertDatabaseCount('tour_booking_events', 1);

        $event = TourBookingEvent::query()->sole();
        $this->assertSame(TourBookingEventType::LoyaltyEligible, $event->event_type);
        $this->assertNull($event->processed_at);
        $this->assertSame($booking->getKey(), $event->payload['booking_id']);
        $this->assertSame($booking->reference, $event->payload['booking_reference']);
        $this->assertSame($owner->getKey(), $event->payload['customer_id']);
        $this->assertSame($booking->total_minor, $event->payload['total_minor']);
        $this->assertSame($booking->currency, $event->payload['currency']);
        $this->assertSame(
            1,
            DB::table('audit_logs')->where('event', 'tour_booking.status_changed')->count(),
        );
    }

    public function test_in_progress_booking_cannot_complete_until_departure_end(): void
    {
        $staff = $this->operationsUser();
        $startsAt = now()->toImmutable()->subHour();
        $departure = $this->bookableDeparture(attributes: [
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addHours(6),
            'cancellation_cutoff_at' => now()->subDay(),
        ]);
        $booking = $this->persistedBooking(
            $this->customer(),
            $departure,
            TourBookingStatus::InProgress,
        );

        try {
            app(TransitionTourBooking::class)->execute(
                $staff,
                $booking,
                TourBookingStatus::Completed,
            );
            $this->fail('A booking completed before the departure ended.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
            $this->assertSame(TourBookingStatus::InProgress, $booking->fresh()->status);
            $this->assertNull($booking->fresh()->completed_at);
            $this->assertDatabaseCount('tour_booking_events', 0);
        }
    }

    public function test_completion_closes_active_assignment_and_clears_current_driver(): void
    {
        $staff = $this->operationsUser();
        $driver = $this->driver();
        $departure = $this->bookableDeparture(attributes: [
            'starts_at' => now()->subDays(2),
            'ends_at' => now()->subHour(),
            'cancellation_cutoff_at' => now()->subDays(3),
        ]);
        $booking = $this->persistedBooking(
            $this->customer(),
            $departure,
            TourBookingStatus::InProgress,
            attributes: ['assigned_driver_user_id' => $driver->getKey()],
        );
        $assignment = TourAssignment::factory()->for($booking, 'booking')->create([
            'driver_user_id' => $driver->getKey(),
            'assigned_by_user_id' => $staff->getKey(),
            'starts_at' => $booking->departure_starts_at_snapshot,
            'ends_at' => $booking->departure_ends_at_snapshot,
            'unassigned_at' => null,
        ]);

        $completed = app(TransitionTourBooking::class)->execute(
            $staff,
            $booking,
            TourBookingStatus::Completed,
        );

        $this->assertSame(TourBookingStatus::Completed, $completed->status);
        $this->assertNull($completed->assigned_driver_user_id);
        $this->assertNotNull($assignment->fresh()->unassigned_at);
        $this->assertSame($staff->getKey(), $assignment->fresh()->unassigned_by_user_id);
        $this->assertSame(0, TourAssignment::query()->active()->count());
    }

    public function test_confirmation_requires_complete_travelers_and_available_capacity(): void
    {
        $staff = $this->operationsUser();
        $owner = $this->customer();
        $departure = $this->bookableDeparture(attributes: ['capacity' => 2]);
        $incomplete = $this->persistedBooking($owner, $departure, travelerCount: 2);
        TourTraveler::query()->where('tour_booking_id', $incomplete->getKey())->firstOrFail()->delete();

        try {
            app(TransitionTourBooking::class)->execute($staff, $incomplete, TourBookingStatus::Confirmed);
            $this->fail('An incomplete traveler list was confirmed.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('travelers', $exception->errors());
        }

        $incomplete->travelers()->create([
            'full_name' => 'Restored Traveler',
            'traveler_type' => 'adult',
            'is_lead' => false,
            'sort_order' => 1,
        ]);
        $this->persistedBooking($this->customer(), $departure, travelerCount: 1);

        try {
            app(TransitionTourBooking::class)->execute($staff, $incomplete, TourBookingStatus::Confirmed);
            $this->fail('An over-capacity departure booking was confirmed.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('capacity', $exception->errors());
            $this->assertSame(TourBookingStatus::Pending, $incomplete->fresh()->status);
        }
    }

    public function test_confirmation_records_an_audit_and_notifies_the_customer_once(): void
    {
        Notification::fake();

        $staff = $this->operationsUser();
        $owner = $this->customer();
        $booking = $this->persistedBooking($owner, $this->bookableDeparture());
        $action = app(TransitionTourBooking::class);

        $action->execute($staff, $booking, TourBookingStatus::Confirmed);
        $action->execute($staff, $booking, TourBookingStatus::Confirmed);

        Notification::assertSentToTimes($owner, TourBookingConfirmedNotification::class, 1);
        $this->assertSame(
            1,
            DB::table('audit_logs')->where('event', 'tour_booking.status_changed')->count(),
        );
    }

    /** @return array<string, array{UserRole, AccountStatus}> */
    public static function unauthorizedOperationsActors(): array
    {
        return [
            'customer' => [UserRole::Customer, AccountStatus::Active],
            'driver' => [UserRole::Driver, AccountStatus::Active],
            'inactive staff' => [UserRole::Staff, AccountStatus::Inactive],
            'suspended manager' => [UserRole::Manager, AccountStatus::Suspended],
        ];
    }

    #[DataProvider('unauthorizedOperationsActors')]
    public function test_non_operations_or_inactive_accounts_cannot_transition_bookings(
        UserRole $role,
        AccountStatus $status,
    ): void {
        $actor = $this->user($role, ['status' => $status]);
        $booking = $this->persistedBooking($this->customer(), $this->bookableDeparture());

        $this->expectException(AuthorizationException::class);

        app(TransitionTourBooking::class)->execute($actor, $booking, TourBookingStatus::Confirmed);
    }
}
