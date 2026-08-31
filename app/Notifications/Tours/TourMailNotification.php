<?php

namespace App\Notifications\Tours;

use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Carbon;

abstract class TourMailNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 120;

    public int $maxExceptions = 3;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public function __construct()
    {
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    /** @return list<RateLimited> */
    public function middleware(object $notifiable, string $channel): array
    {
        return $channel === 'mail'
            ? [new RateLimited('tour-notification-mail')]
            : [];
    }

    protected function departureLabel(string $startsAt): string
    {
        return Carbon::parse($startsAt)
            ->timezone((string) config('pisfa.business_timezone', 'Africa/Kampala'))
            ->format('j F Y \a\t g:i A T');
    }

    protected function moneyLabel(int $minorAmount, string $currency): string
    {
        return Money::format($minorAmount, $currency);
    }

    protected function bookingUrl(string $reference): string
    {
        return rtrim((string) config('app.url'), '/')
            .'/portal/bookings/'.rawurlencode($reference);
    }

    protected function dashboardUrl(): string
    {
        return rtrim((string) config('app.url'), '/').'/dashboard';
    }
}
