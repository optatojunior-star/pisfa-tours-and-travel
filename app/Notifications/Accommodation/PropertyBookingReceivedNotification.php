<?php

namespace App\Notifications\Accommodation;

use Illuminate\Notifications\Messages\MailMessage;

class PropertyBookingReceivedNotification extends AccommodationMailNotification
{
    public function __construct(
        public readonly string $bookingReference,
        public readonly string $propertyName,
        public readonly string $roomTypeName,
        public readonly string $stay,
        public readonly string $total,
    ) {
        parent::__construct();
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('PISFA received your accommodation request')
            ->greeting('Hello '.$notifiable->name.',')
            ->line('We received your request to stay at '.$this->propertyName.'.')
            ->line('Reference: '.$this->bookingReference)
            ->line('Room: '.$this->roomTypeName)
            ->line('Dates: '.$this->stay)
            ->line('Total: '.$this->total)
            ->line('This request is pending review. No payment has been taken, and the rooms are held for you in the meantime.')
            ->action('View your stay', $this->bookingUrl($this->bookingReference));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'property_booking_received',
            'reference' => $this->bookingReference,
            'title' => 'Accommodation request received',
            'message' => 'Your stay at '.$this->propertyName.' is pending review. No payment has been taken.',
            'url' => $this->bookingUrl($this->bookingReference),
        ];
    }
}
