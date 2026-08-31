<?php

namespace App\Notifications\Tours;

use Illuminate\Notifications\Messages\MailMessage;

class TourDriverAssignedNotification extends TourMailNotification
{
    public function __construct(
        public readonly string $bookingReference,
        public readonly string $tourName,
        public readonly string $departureStartsAt,
        public readonly string $counterpartName,
        public readonly ?string $counterpartPhone,
        public readonly bool $forDriver,
    ) {
        parent::__construct();
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject($this->forDriver ? 'New PISFA tour assignment' : 'Driver assigned to your PISFA tour')
            ->greeting('Hello '.$notifiable->name.',')
            ->line(($this->forDriver ? 'You have been assigned to ' : 'A driver has been assigned to ')
                .$this->tourName.'.')
            ->line('Reference: '.$this->bookingReference)
            ->line('Departure: '.$this->departureLabel($this->departureStartsAt))
            ->line(($this->forDriver ? 'Lead customer: ' : 'Driver: ').$this->counterpartName);

        if (filled($this->counterpartPhone)) {
            $message->line('Contact telephone: '.$this->counterpartPhone);
        }

        return $message->action(
            $this->forDriver ? 'Open dashboard' : 'View booking',
            $this->forDriver ? $this->dashboardUrl() : $this->bookingUrl($this->bookingReference),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => $this->forDriver ? 'tour_driver_assignment' : 'tour_driver_assigned',
            'reference' => $this->bookingReference,
            'title' => $this->forDriver ? 'New tour assignment' : 'Tour driver assigned',
            'message' => ($this->forDriver ? 'You are assigned to ' : 'A driver is assigned to ')
                .$this->tourName.'.',
            'url' => $this->forDriver ? $this->dashboardUrl() : $this->bookingUrl($this->bookingReference),
        ];
    }
}
