<?php

namespace App\Notifications\Leasing;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\Middleware\RateLimited;

abstract class LeasingMailNotification extends Notification implements ShouldQueue
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
        // A guest applicant has no account, so there is no inbox to write to.
        return $notifiable instanceof AnonymousNotifiable ? ['mail'] : ['mail', 'database'];
    }

    /** @return list<RateLimited> */
    public function middleware(object $notifiable, string $channel): array
    {
        return $channel === 'mail' ? [new RateLimited('leasing-notification-mail')] : [];
    }

    protected function applicationUrl(string $reference): string
    {
        return rtrim((string) config('app.url'), '/').'/lease-your-car/'.rawurlencode($reference);
    }

    protected function leaseUrl(string $reference): string
    {
        return rtrim((string) config('app.url'), '/').'/portal/leases/'.rawurlencode($reference);
    }
}
