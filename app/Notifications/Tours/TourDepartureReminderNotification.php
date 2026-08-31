<?php

namespace App\Notifications\Tours;

use Illuminate\Notifications\Messages\MailMessage;

class TourDepartureReminderNotification extends TourMailNotification
{
    public function __construct(
        public readonly string $bookingReference,
        public readonly string $tourName,
        public readonly string $departureStartsAt,
    ) {
        parent::__construct();
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Reminder: your PISFA tour departs soon')
            ->greeting('Hello '.$notifiable->name.',')
            ->line('This is a reminder for your confirmed '.$this->tourName.' booking.')
            ->line('Reference: '.$this->bookingReference)
            ->line('Departure: '.$this->departureLabel($this->departureStartsAt))
            ->line('Please review your itinerary and contact PISFA promptly if you need operational assistance.')
            ->action('View booking', $this->bookingUrl($this->bookingReference));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'tour_departure_reminder',
            'reference' => $this->bookingReference,
            'title' => 'Tour departure reminder',
            'message' => $this->tourName.' departs '.$this->departureLabel($this->departureStartsAt).'.',
            'url' => $this->bookingUrl($this->bookingReference),
        ];
    }
}
