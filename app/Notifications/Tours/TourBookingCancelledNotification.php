<?php

namespace App\Notifications\Tours;

use Illuminate\Notifications\Messages\MailMessage;

class TourBookingCancelledNotification extends TourMailNotification
{
    public function __construct(
        public readonly string $bookingReference,
        public readonly string $tourName,
        public readonly string $departureStartsAt,
        public readonly ?string $reason = null,
    ) {
        parent::__construct();
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject('Your PISFA tour booking was cancelled')
            ->greeting('Hello '.$notifiable->name.',')
            ->line('Booking '.$this->bookingReference.' for '.$this->tourName.' has been cancelled.')
            ->line('The planned departure was '.$this->departureLabel($this->departureStartsAt).'.');

        if (filled($this->reason)) {
            $message->line('Reason: '.$this->reason);
        }

        return $message
            ->line('This cancellation does not itself create or promise a refund. Any payment or refund is handled separately through an authorized PISFA workflow.')
            ->action('View booking', $this->bookingUrl($this->bookingReference));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'tour_booking_cancelled',
            'reference' => $this->bookingReference,
            'title' => 'Tour booking cancelled',
            'message' => $this->tourName.' was cancelled.'.($this->reason ? ' '.$this->reason : ''),
            'url' => $this->bookingUrl($this->bookingReference),
        ];
    }
}
