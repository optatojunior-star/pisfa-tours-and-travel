<?php

namespace App\Notifications\Reviews;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\Middleware\RateLimited;

/**
 * Invites a customer to review a completed booking. Sent once per booking; the
 * sending command records a marker under a unique constraint, so a repeated
 * sweep cannot mail the same customer again.
 */
class ReviewRequestNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 120;

    public int $maxExceptions = 3;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public function __construct(
        public readonly string $bookingReference,
        public readonly string $tourName,
        public readonly string $reviewUrl,
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
        return $channel === 'mail' ? [new RateLimited('review-notification-mail')] : [];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('How was your PISFA trip?')
            ->greeting('Hello '.$notifiable->name.',')
            ->line('Thank you for travelling with PISFA on '.$this->tourName.'.')
            ->line('Reference: '.$this->bookingReference)
            ->line('If you have a few minutes, tell other travellers how it went. Reviews are read by our team before they are published, and your name is shown as a first name and surname initial.')
            ->action('Write a review', $this->reviewUrl)
            ->line('If you would rather not, no action is needed — we will not ask again about this trip.');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'review_request',
            'reference' => $this->bookingReference,
            'title' => 'Tell us about your trip',
            'message' => 'Share how '.$this->tourName.' went.',
            'url' => $this->reviewUrl,
        ];
    }
}
