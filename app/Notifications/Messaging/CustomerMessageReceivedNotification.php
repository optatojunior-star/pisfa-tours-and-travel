<?php

namespace App\Notifications\Messaging;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\Middleware\RateLimited;

/**
 * Tells the member of staff who owns a thread that the customer has written.
 *
 * Only ever sent to the assignee. A notification to everybody on every message
 * is how an inbox gets muted, and a muted inbox is worse than none.
 */
class CustomerMessageReceivedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 120;

    public int $maxExceptions = 3;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public function __construct(
        public readonly string $reference,
        public readonly string $contactName,
        public readonly string $channel,
        public readonly string $preview,
        public readonly string $url,
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
        return $channel === 'mail' ? [new RateLimited('messaging-notification-mail')] : [];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('New message from '.$this->contactName.' ('.$this->reference.')')
            ->greeting('Hello,')
            ->line($this->contactName.' has written on '.$this->channel.'.')
            // A preview, not the whole message: email is a less controlled
            // place than the console, and the thread is one click away.
            ->line('"'.$this->preview.'"')
            ->action('Open the conversation', $this->url)
            ->line('Reference: '.$this->reference);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'conversation.message',
            'reference' => $this->reference,
            'contact_name' => $this->contactName,
            'channel' => $this->channel,
            'preview' => $this->preview,
            'url' => $this->url,
        ];
    }
}
