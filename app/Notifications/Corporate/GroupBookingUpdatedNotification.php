<?php

namespace App\Notifications\Corporate;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\Middleware\RateLimited;

class GroupBookingUpdatedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 120;

    public int $maxExceptions = 3;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public function __construct(
        public readonly string $reference,
        public readonly string $title,
        public readonly string $statusLabel,
        public readonly string $dates,
        public readonly string $message,
    ) {
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
        return $channel === 'mail' ? [new RateLimited('corporate-notification-mail')] : [];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your group booking: '.$this->statusLabel)
            ->greeting('Hello '.$notifiable->name.',')
            ->line($this->message)
            ->line('Group: '.$this->title)
            ->line('Reference: '.$this->reference)
            ->line('Dates: '.$this->dates)
            ->action('View the group', $this->url());
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'group_booking_updated',
            'reference' => $this->reference,
            'title' => 'Group booking: '.$this->statusLabel,
            'message' => $this->message,
            'url' => $this->url(),
        ];
    }

    private function url(): string
    {
        return rtrim((string) config('app.url'), '/').'/portal/groups/'.rawurlencode($this->reference);
    }
}
