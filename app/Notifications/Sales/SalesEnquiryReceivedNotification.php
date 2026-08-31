<?php

namespace App\Notifications\Sales;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\Middleware\RateLimited;

class SalesEnquiryReceivedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 120;

    public int $maxExceptions = 3;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public function __construct(
        public readonly string $recipientName,
        public readonly string $reference,
        public readonly string $listingTitle,
        public readonly string $askingPrice,
        public readonly string $listingUrl,
    ) {
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        // A guest has no account, so there is no database channel to write to.
        return $notifiable instanceof AnonymousNotifiable ? ['mail'] : ['mail', 'database'];
    }

    /** @return list<RateLimited> */
    public function middleware(object $notifiable, string $channel): array
    {
        return $channel === 'mail' ? [new RateLimited('sales-notification-mail')] : [];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('We have your enquiry about the '.$this->listingTitle)
            ->greeting('Hello '.trim($this->recipientName).',')
            ->line('Thank you for asking about the '.$this->listingTitle.'.')
            ->line('Reference: '.$this->reference)
            ->line('Asking price: '.$this->askingPrice)
            ->line('A member of the team will be in touch to arrange a viewing or answer your questions. '
                .'Nothing is reserved until we confirm it with you.')
            ->action('View the listing', $this->listingUrl);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'sales_enquiry_received',
            'reference' => $this->reference,
            'title' => 'Showroom enquiry received',
            'message' => 'We are following up on the '.$this->listingTitle.'.',
            'url' => $this->listingUrl,
        ];
    }
}
