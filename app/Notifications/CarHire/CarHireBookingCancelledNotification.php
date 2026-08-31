<?php

namespace App\Notifications\CarHire;

use Illuminate\Notifications\Messages\MailMessage;

class CarHireBookingCancelledNotification extends CarHireMailNotification
{
    public function __construct(
        public readonly string $bookingReference,
        public readonly string $vehicleName,
        public readonly string $pickupAt,
        public readonly string $reason,
    ) {
        parent::__construct();
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your PISFA vehicle hire was cancelled')
            ->greeting('Hello '.$notifiable->name.',')
            ->line('Booking '.$this->bookingReference.' for '.$this->vehicleName.' was cancelled.')
            ->line('Planned pickup: '.$this->dateTimeLabel($this->pickupAt))
            ->line('Reason: '.$this->reason)
            ->line('This action does not itself create or promise a refund. Any future payment adjustment belongs to an authorized F14 workflow.')
            ->action('View hire booking', $this->bookingUrl($this->bookingReference));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'car_hire_booking_cancelled',
            'reference' => $this->bookingReference,
            'title' => 'Vehicle hire cancelled',
            'message' => $this->vehicleName.' was cancelled. '.$this->reason,
            'url' => $this->bookingUrl($this->bookingReference),
        ];
    }
}
