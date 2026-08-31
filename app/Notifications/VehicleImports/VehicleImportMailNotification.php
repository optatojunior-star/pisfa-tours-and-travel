<?php

namespace App\Notifications\VehicleImports;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Carbon;

abstract class VehicleImportMailNotification extends Notification implements ShouldQueue
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
        return $notifiable instanceof AnonymousNotifiable ? ['mail'] : ['mail', 'database'];
    }

    /** @return list<RateLimited> */
    public function middleware(object $notifiable, string $channel): array
    {
        return $channel === 'mail' ? [new RateLimited('vehicle-import-notification-mail')] : [];
    }

    protected function greeting(string $recipientName): string
    {
        return 'Hello '.trim($recipientName).',';
    }

    protected function dateLabel(string $value): string
    {
        return Carbon::parse($value)
            ->timezone((string) config('pisfa.business_timezone', 'Africa/Kampala'))
            ->format('j F Y');
    }
}
