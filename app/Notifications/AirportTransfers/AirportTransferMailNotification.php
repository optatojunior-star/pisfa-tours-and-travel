<?php

namespace App\Notifications\AirportTransfers;

use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Carbon;

abstract class AirportTransferMailNotification extends Notification implements ShouldQueue
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
        return $notifiable instanceof AnonymousNotifiable
            ? ['mail']
            : ['mail', 'database'];
    }

    /** @return list<RateLimited> */
    public function middleware(object $notifiable, string $channel): array
    {
        return $channel === 'mail'
            ? [new RateLimited('airport-transfer-notification-mail')]
            : [];
    }

    protected function greeting(string $recipientName): string
    {
        return 'Hello '.trim($recipientName).',';
    }

    protected function dateTimeLabel(string $value): string
    {
        return Carbon::parse($value)
            ->timezone((string) config('pisfa.business_timezone', 'Africa/Kampala'))
            ->format('j F Y \a\t g:i A T');
    }

    protected function moneyLabel(int $minorAmount, string $currency): string
    {
        return Money::format($minorAmount, $currency);
    }

    protected function portalUrl(string $reference): string
    {
        return rtrim((string) config('app.url'), '/').'/portal/airport-transfers/'.rawurlencode($reference);
    }

    protected function dashboardUrl(): string
    {
        return rtrim((string) config('app.url'), '/').'/dashboard';
    }
}
