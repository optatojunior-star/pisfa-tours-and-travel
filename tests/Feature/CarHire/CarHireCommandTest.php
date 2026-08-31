<?php

namespace Tests\Feature\CarHire;

use App\Enums\CarHireBookingEventType;
use App\Enums\CarHireBookingStatus;
use App\Models\AuditLog;
use App\Models\CarHireBookingEvent;
use App\Notifications\CarHire\CarHireReturnReminderNotification;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\CarHire\Concerns\BuildsCarHireFixtures;
use Tests\TestCase;

class CarHireCommandTest extends TestCase
{
    use BuildsCarHireFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-08-20 09:00:00');
        Notification::fake();
    }

    public function test_expiry_command_changes_only_elapsed_pending_holds_and_is_idempotent(): void
    {
        [$vehicle, $rate] = $this->bookableVehicle();
        $expired = $this->persistedBooking(
            $this->customer(),
            $vehicle,
            $rate,
            attributes: ['hold_expires_at' => now()->subMinute()],
        );
        $contract = $this->contractFor($expired);
        $future = $this->persistedBooking(
            $this->customer(),
            $vehicle,
            $rate,
            attributes: [
                'pickup_at' => now()->addDays(20),
                'return_at' => now()->addDays(23),
                'hold_expires_at' => now()->addHour(),
            ],
        );
        $confirmed = $this->persistedBooking(
            $this->customer(),
            $vehicle,
            $rate,
            CarHireBookingStatus::Confirmed,
            attributes: [
                'pickup_at' => now()->addDays(30),
                'return_at' => now()->addDays(33),
                'hold_expires_at' => now()->subMinute(),
            ],
        );

        $this->assertSame(0, Artisan::call('car-hire:expire-pending-bookings'));
        $this->assertSame(0, Artisan::call('car-hire:expire-pending-bookings'));

        $this->assertSame(CarHireBookingStatus::Expired, $expired->fresh()->status);
        $this->assertSame(CarHireBookingStatus::Pending, $future->fresh()->status);
        $this->assertSame(CarHireBookingStatus::Confirmed, $confirmed->fresh()->status);
        $this->assertNotNull($contract->fresh()->voided_at);
        $this->assertSame(1, CarHireBookingEvent::query()
            ->where('car_hire_booking_id', $expired->getKey())
            ->where('event_type', CarHireBookingEventType::BookingExpired->value)
            ->count());
        $this->assertSame(1, AuditLog::query()
            ->where('event', 'car_hire.booking_expired')
            ->where('auditable_id', $expired->getKey())
            ->count());
    }

    public function test_return_reminder_uses_configured_window_and_is_idempotent(): void
    {
        config()->set('car_hire.return_reminders.lead_minutes', 60);
        config()->set('car_hire.return_reminders.window_minutes', 10);
        [$vehicle, $rate] = $this->bookableVehicle();
        $customer = $this->customer();
        $eligible = $this->persistedBooking(
            $customer,
            $vehicle,
            $rate,
            CarHireBookingStatus::Confirmed,
            attributes: [
                'pickup_at' => now()->subHour(),
                'return_at' => now()->addMinutes(65),
            ],
        );
        $outside = $this->persistedBooking(
            $this->customer(),
            $vehicle,
            $rate,
            CarHireBookingStatus::Confirmed,
            attributes: [
                'pickup_at' => now()->subHour(),
                'return_at' => now()->addMinutes(71),
            ],
        );

        $this->assertSame(0, Artisan::call('car-hire:send-return-reminders'));
        $this->assertSame(0, Artisan::call('car-hire:send-return-reminders'));

        Notification::assertSentToTimes($customer, CarHireReturnReminderNotification::class, 1);
        Notification::assertNotSentTo($outside->customer, CarHireReturnReminderNotification::class);
        $event = CarHireBookingEvent::query()
            ->where('car_hire_booking_id', $eligible->getKey())
            ->where('event_type', CarHireBookingEventType::ReturnReminderSent->value)
            ->sole();
        $this->assertNotNull($event->processed_at);
        $this->assertSame(1, CarHireBookingEvent::query()
            ->where('event_type', CarHireBookingEventType::ReturnReminderSent->value)->count());
    }

    public function test_both_car_hire_commands_are_scheduled_every_minute_without_overlap(): void
    {
        $events = collect(app(Schedule::class)->events());

        foreach ([
            'car-hire:expire-pending-bookings',
            'car-hire:send-return-reminders',
        ] as $command) {
            $event = $events->first(fn ($event): bool => str_contains((string) $event->command, $command));
            $this->assertNotNull($event, "{$command} is missing from the scheduler.");
            $this->assertSame('* * * * *', $event->expression);
            $this->assertTrue($event->withoutOverlapping);
        }
    }
}
