<?php

namespace App\Notifications\AirportTransfers;

use Illuminate\Notifications\Messages\MailMessage;

class AirportTransferPickupReminderNotification extends AirportTransferMailNotification
{
    public function __construct(
        public readonly string $recipientName,
        public readonly string $bookingReference,
        public readonly string $serviceStartsAt,
        public readonly string $airportName,
        public readonly string $locationName,
        public readonly string $viewUrl,
    ) {
        parent::__construct();
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Reminder: your PISFA airport transfer is approaching')
            ->greeting($this->greeting($this->recipientName))
            ->line('Your airport transfer is scheduled soon.')
            ->line('Reference: '.$this->bookingReference)
            ->line('Service time: '.$this->dateTimeLabel($this->serviceStartsAt))
            ->line('Route: '.$this->airportName.' — '.$this->locationName)
            ->action('View transfer booking', $this->viewUrl);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'airport_transfer_pickup_reminder',
            'reference' => $this->bookingReference,
            'title' => 'Airport transfer reminder',
            'message' => 'Your airport transfer is scheduled for '.$this->dateTimeLabel($this->serviceStartsAt).'.',
            'url' => $this->viewUrl,
        ];
    }
}
