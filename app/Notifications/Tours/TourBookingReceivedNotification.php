<?php

namespace App\Notifications\Tours;

use Illuminate\Notifications\Messages\MailMessage;

class TourBookingReceivedNotification extends TourMailNotification
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
            ->subject('PISFA received your tour booking request')
            ->greeting('Hello '.$notifiable->name.',')
            ->line('We received your booking request for '.$this->tourName.'.')
            ->line('Reference: '.$this->bookingReference)
            ->line('Departure: '.$this->departureLabel($this->departureStartsAt))
            ->line('Booking total: '.$this->moneyLabel($this->totalMinor, $this->currency))
            ->line('Your request is pending review. No payment has been taken by this booking step.')
            ->line('PISFA will send a separate confirmation before you travel.')
            ->action('View booking', $this->bookingUrl($this->bookingReference));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'tour_booking_received',
            'reference' => $this->bookingReference,
            'title' => 'Tour booking received',
            'message' => 'Your booking request is pending review. No payment has been taken.',
            'url' => $this->bookingUrl($this->bookingReference),
        ];
    }
}
