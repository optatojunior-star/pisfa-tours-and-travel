<?php

namespace App\Notifications\Accommodation;

use Illuminate\Notifications\Messages\MailMessage;

class PropertyBookingUpdatedNotification extends AccommodationMailNotification
{
    public function __construct(
        public readonly string $bookingReference,
        public readonly string $propertyName,
        public readonly string $stay,
        public readonly string $statusLabel,
        public readonly string $message,
    ) {
        parent::__construct();
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Update on your stay at '.$this->propertyName)
            ->greeting('Hello '.$notifiable->name.',')
            ->line($this->message)
            ->line('Reference: '.$this->bookingReference)
            ->line('Dates: '.$this->stay)
            ->line('Status: '.$this->statusLabel)
            ->action('View your stay', $this->bookingUrl($this->bookingReference));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'property_booking_updated',
            'reference' => $this->bookingReference,
            'title' => 'Your stay: '.$this->statusLabel,
            'message' => $this->message,
            'url' => $this->bookingUrl($this->bookingReference),
        ];
    }
}
