<?php

namespace Tests\Feature\CarHire;

use App\Notifications\CarHire\CarHireBookingReceivedNotification;
use Illuminate\Queue\Middleware\RateLimited;
use stdClass;
use Tests\TestCase;

class CarHireNotificationContractTest extends TestCase
{
    public function test_car_hire_notifications_are_after_commit_and_only_rate_limit_mail(): void
    {
        $notification = new CarHireBookingReceivedNotification(
            bookingReference: 'HIRE-NOTIFICATION-CONTRACT',
            vehicleName: 'Notification Contract Vehicle',
            pickupAt: '2026-09-10T05:00:00+00:00',
            totalMinor: 750_000,
            currency: 'UGX',
        );
        $notifiable = new stdClass;

        $this->assertSame(['mail', 'database'], $notification->via($notifiable));
        $this->assertTrue($notification->afterCommit);
        $this->assertCount(1, $notification->middleware($notifiable, 'mail'));
        $this->assertContainsOnlyInstancesOf(
            RateLimited::class,
            $notification->middleware($notifiable, 'mail'),
        );
        $this->assertSame([], $notification->middleware($notifiable, 'database'));
        $this->assertSame([], $notification->middleware($notifiable, 'broadcast'));
        $this->assertSame(120, $notification->tries);
        $this->assertSame(3, $notification->maxExceptions);
        $this->assertSame([60, 300, 900], $notification->backoff);
    }
}
