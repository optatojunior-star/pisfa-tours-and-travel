<?php

namespace Tests\Feature\Tours;

use App\Enums\TourBookingEventType;
use App\Enums\TourBookingStatus;
use App\Enums\TourDepartureStatus;
use App\Models\TourBookingEvent;
use App\Notifications\Tours\TourDepartureReminderNotification;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Tours\Concerns\BuildsTourFixtures;
use Tests\TestCase;

class DepartureReminderCommandTest extends TestCase
{
    use BuildsTourFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-08-20 09:00:00');
        config()->set('tours.reminders.lead_minutes', 60);
        config()->set('tours.reminders.window_minutes', 15);
    }

    public function test_departure_reminder_command_is_scheduled_every_minute(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($scheduled): bool => str_contains(
                (string) $scheduled->command,
                'tours:send-departure-reminders',
            ));

        $this->assertNotNull($event, 'The departure reminder command is not scheduled.');
        $this->assertSame('* * * * *', $event->expression);
    }

    public function test_running_reminder_command_twice_creates_one_event_and_one_notification(): void
    {
        Notification::fake();

        $customer = $this->customer();
        $startsAt = now()->toImmutable()->addMinutes(65);
        $departure = $this->bookableDeparture(attributes: [
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addHours(8),
            'cancellation_cutoff_at' => now()->addMinutes(30),
        ]);
        $booking = $this->persistedBooking($customer, $departure, TourBookingStatus::Confirmed);

        $this->artisan('tours:send-departure-reminders')
            ->expectsOutputToContain('1 marker(s) created; 1 notification(s) queued; 0 failure(s)')
            ->assertSuccessful();
        $this->artisan('tours:send-departure-reminders')
            ->expectsOutputToContain('0 marker(s) created; 0 notification(s) queued; 0 failure(s)')
            ->assertSuccessful();

        $this->assertDatabaseCount('tour_booking_events', 1);
        $event = TourBookingEvent::query()->sole();
        $this->assertSame(TourBookingEventType::DepartureReminderSent, $event->event_type);
        $this->assertSame($booking->getKey(), $event->tour_booking_id);
        $this->assertSame($booking->reference, $event->payload['booking_reference']);
        $this->assertSame(
            $booking->departure_starts_at_snapshot->toIso8601String(),
            $event->payload['departure_starts_at'],
        );
        $this->assertNotNull($event->processed_at);
        Notification::assertSentToTimes($customer, TourDepartureReminderNotification::class, 1);
    }

    public function test_confirmed_scheduled_and_closed_departures_receive_one_reminder_while_terminal_departures_are_excluded(): void
    {
        Notification::fake();

        $includedCustomer = $this->customer();
        $lowerBoundary = now()->toImmutable()->addMinutes(60);
        $includedDeparture = $this->bookableDeparture(attributes: [
            'starts_at' => $lowerBoundary,
            'ends_at' => $lowerBoundary->addHours(4),
            'cancellation_cutoff_at' => now()->addMinutes(20),
        ]);
        $included = $this->persistedBooking(
            $includedCustomer,
            $includedDeparture,
            TourBookingStatus::Confirmed,
        );

        $upperBoundary = now()->toImmutable()->addMinutes(75);
        $upperDeparture = $this->bookableDeparture(attributes: [
            'starts_at' => $upperBoundary,
            'ends_at' => $upperBoundary->addHours(4),
            'cancellation_cutoff_at' => now()->addMinutes(20),
        ]);
        $upperCustomer = $this->customer();
        $upperExcluded = $this->persistedBooking(
            $upperCustomer,
            $upperDeparture,
            TourBookingStatus::Confirmed,
        );

        $pendingDeparture = $this->bookableDeparture(attributes: [
            'starts_at' => now()->toImmutable()->addMinutes(65),
            'ends_at' => now()->toImmutable()->addHours(5),
            'cancellation_cutoff_at' => now()->addMinutes(20),
        ]);
        $pendingCustomer = $this->customer();
        $pending = $this->persistedBooking(
            $pendingCustomer,
            $pendingDeparture,
            TourBookingStatus::Pending,
        );

        $closedDeparture = $this->bookableDeparture(attributes: [
            'starts_at' => now()->toImmutable()->addMinutes(66),
            'ends_at' => now()->toImmutable()->addHours(5),
            'cancellation_cutoff_at' => now()->addMinutes(20),
            'status' => TourDepartureStatus::Closed,
        ]);
        $closedCustomer = $this->customer();
        $closed = $this->persistedBooking(
            $closedCustomer,
            $closedDeparture,
            TourBookingStatus::Confirmed,
        );

        $cancelledDeparture = $this->bookableDeparture(attributes: [
            'starts_at' => now()->toImmutable()->addMinutes(67),
            'ends_at' => now()->toImmutable()->addHours(5),
            'cancellation_cutoff_at' => now()->addMinutes(20),
            'status' => TourDepartureStatus::Cancelled,
        ]);
        $cancelledCustomer = $this->customer();
        $cancelled = $this->persistedBooking(
            $cancelledCustomer,
            $cancelledDeparture,
            TourBookingStatus::Confirmed,
        );

        $completedDeparture = $this->bookableDeparture(attributes: [
            'starts_at' => now()->toImmutable()->addMinutes(68),
            'ends_at' => now()->toImmutable()->addHours(5),
            'cancellation_cutoff_at' => now()->addMinutes(20),
            'status' => TourDepartureStatus::Completed,
        ]);
        $completedCustomer = $this->customer();
        $completed = $this->persistedBooking(
            $completedCustomer,
            $completedDeparture,
            TourBookingStatus::Confirmed,
        );

        $this->artisan('tours:send-departure-reminders')->assertSuccessful();
        $this->artisan('tours:send-departure-reminders')->assertSuccessful();

        foreach ([$included, $closed] as $reminded) {
            $this->assertSame(1, TourBookingEvent::query()
                ->where('tour_booking_id', $reminded->getKey())
                ->where('event_type', TourBookingEventType::DepartureReminderSent->value)
                ->count());
        }
        foreach ([$upperExcluded, $pending, $cancelled, $completed] as $excluded) {
            $this->assertDatabaseMissing('tour_booking_events', [
                'tour_booking_id' => $excluded->getKey(),
                'event_type' => TourBookingEventType::DepartureReminderSent->value,
            ]);
        }
        $this->assertDatabaseCount('tour_booking_events', 2);
        Notification::assertSentToTimes($includedCustomer, TourDepartureReminderNotification::class, 1);
        Notification::assertSentToTimes($closedCustomer, TourDepartureReminderNotification::class, 1);
        foreach ([$upperCustomer, $pendingCustomer, $cancelledCustomer, $completedCustomer] as $notReminded) {
            Notification::assertNotSentTo($notReminded, TourDepartureReminderNotification::class);
        }
    }

    public function test_preexisting_pending_marker_is_retried_even_after_creation_window_has_moved(): void
    {
        Notification::fake();

        $customer = $this->customer();
        $startsAt = now()->toImmutable()->addHours(8);
        $departure = $this->bookableDeparture(attributes: [
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addHours(4),
            'cancellation_cutoff_at' => now()->addHours(2),
        ]);
        $booking = $this->persistedBooking($customer, $departure, TourBookingStatus::Confirmed);
        $event = TourBookingEvent::factory()->for($booking, 'booking')->create([
            'event_type' => TourBookingEventType::DepartureReminderSent,
            'processed_at' => null,
        ]);

        $this->artisan('tours:send-departure-reminders')
            ->expectsOutputToContain('0 marker(s) created; 1 notification(s) queued; 0 failure(s)')
            ->assertSuccessful();

        $this->assertNotNull($event->fresh()->processed_at);
        Notification::assertSentToTimes($customer, TourDepartureReminderNotification::class, 1);
    }
}
