<?php

namespace App\Notifications\CarHire;

use Illuminate\Notifications\Messages\MailMessage;

class CarHireBookingConfirmedNotification extends CarHireMailNotification
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
            ->subject('Your PISFA vehicle hire is confirmed')
            ->greeting('Hello '.$notifiable->name.',')
            ->line('Your request for '.$this->vehicleName.' is confirmed for operational preparation.')
            ->line('Reference: '.$this->bookingReference)
            ->line('Pickup: '.$this->dateTimeLabel($this->pickupAt))
            ->line('Rental total and refundable deposit: '.$this->moneyLabel($this->totalMinor, $this->currency))
            ->line('Confirmation does not mean an online payment was taken. Use only a separately authorized PISFA payment request when F14 payment processing becomes available.')
            ->action('View hire booking', $this->bookingUrl($this->bookingReference));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'car_hire_booking_confirmed',
            'reference' => $this->bookingReference,
            'title' => 'Vehicle hire confirmed',
            'message' => $this->vehicleName.' is confirmed for '.$this->dateTimeLabel($this->pickupAt).'.',
            'url' => $this->bookingUrl($this->bookingReference),
        ];
    }
}
