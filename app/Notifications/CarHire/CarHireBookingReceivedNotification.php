<?php

namespace App\Notifications\CarHire;

use Illuminate\Notifications\Messages\MailMessage;

class CarHireBookingReceivedNotification extends CarHireMailNotification
{
    public function __construct(
        public readonly string $bookingReference,
        public readonly string $vehicleName,
        public readonly string $pickupAt,
        public readonly int $totalMinor,
        public readonly string $currency,
    ) {
        parent::__construct();
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('PISFA received your vehicle hire request')
            ->greeting('Hello '.$notifiable->name.',')
            ->line('We received your request for '.$this->vehicleName.'.')
            ->line('Reference: '.$this->bookingReference)
            ->line('Pickup: '.$this->dateTimeLabel($this->pickupAt))
            ->line('Rental total and refundable deposit: '.$this->moneyLabel($this->totalMinor, $this->currency))
            ->line('This request is pending review. No payment has been taken.')
            ->action('View hire request', $this->bookingUrl($this->bookingReference));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'car_hire_booking_received',
            'reference' => $this->bookingReference,
            'title' => 'Vehicle hire request received',
            'message' => 'Your vehicle hire request is pending review. No payment has been taken.',
            'url' => $this->bookingUrl($this->bookingReference),
        ];
    }
}
