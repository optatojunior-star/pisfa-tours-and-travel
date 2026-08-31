<?php

namespace App\Notifications\Tours;

use Illuminate\Notifications\Messages\MailMessage;

class TourBookingConfirmedNotification extends TourMailNotification
{
    public function __construct(
        public readonly string $bookingReference,
        public readonly string $tourName,
        public readonly string $departureStartsAt,
        public readonly int $totalMinor,
        public readonly string $currency,
    ) {
        parent::__construct();
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your PISFA tour booking is confirmed')
            ->greeting('Hello '.$notifiable->name.',')
            ->line('Your booking for '.$this->tourName.' is confirmed.')
            ->line('Reference: '.$this->bookingReference)
            ->line('Departure: '.$this->departureLabel($this->departureStartsAt))
            ->line('Booking total: '.$this->moneyLabel($this->totalMinor, $this->currency))
            ->line('No payment was taken when you submitted this booking. Use only a separately authorized PISFA payment request when payment becomes available.')
            ->action('View booking', $this->bookingUrl($this->bookingReference));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'tour_booking_confirmed',
            'reference' => $this->bookingReference,
            'title' => 'Tour booking confirmed',
            'message' => $this->tourName.' is confirmed for '.$this->departureLabel($this->departureStartsAt).'.',
            'url' => $this->bookingUrl($this->bookingReference),
        ];
    }
}
