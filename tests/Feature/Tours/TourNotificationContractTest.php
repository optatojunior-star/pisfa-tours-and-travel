<?php

namespace Tests\Feature\Tours;

use App\Notifications\Tours\TourBookingReceivedNotification;
use Illuminate\Queue\Middleware\RateLimited;
use stdClass;
use Tests\TestCase;

class TourNotificationContractTest extends TestCase
{
    public function test_tour_mail_notifications_rate_limit_only_mail_and_use_hosting_safe_retry_limits(): void
    {
        $notification = new TourBookingReceivedNotification(
            bookingReference: 'TOUR-NOTIFICATION-CONTRACT',
            tourName: 'Notification Contract Tour',
            departureStartsAt: '2026-09-10T05:00:00+00:00',
            totalMinor: 250_000,
            currency: 'UGX',
        );
        $notifiable = new stdClass;

        $this->assertSame(['mail', 'database'], $notification->via($notifiable));
        $this->assertCount(1, $notification->middleware($notifiable, 'mail'));
        $this->assertContainsOnlyInstancesOf(
            RateLimited::class,
            $notification->middleware($notifiable, 'mail'),
        );
        $this->assertSame([], $notification->middleware($notifiable, 'database'));
        $this->assertSame([], $notification->middleware($notifiable, 'broadcast'));
        $this->assertSame(120, $notification->tries);
        $this->assertSame(3, $notification->maxExceptions);
    }
}
