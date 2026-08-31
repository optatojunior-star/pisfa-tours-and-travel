<?php

namespace App\Notifications\AirportTransfers;

use Illuminate\Notifications\Messages\MailMessage;

class AirportTransferBookingConfirmedNotification extends AirportTransferMailNotification
{
    public function __construct(
        public readonly string $recipientName,
        public readonly string $bookingReference,
        public readonly string $transferLabel,
        public readonly string $airportName,
        public readonly string $locationName,
        public readonly string $serviceStartsAt,
        public readonly int $amountMinor,
        public readonly string $currency,
        public readonly string $vehicleName,
        public readonly string $driverName,
        public readonly string $viewUrl,
    ) {
        parent::__construct();
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your PISFA airport transfer is confirmed')
            ->greeting($this->greeting($this->recipientName))
            ->line('Your '.$this->transferLabel.' is confirmed with a driver and vehicle reserved.')
            ->line('Reference: '.$this->bookingReference)
            ->line('Route: '.$this->airportName.' — '.$this->locationName)
            ->line('Service time: '.$this->dateTimeLabel($this->serviceStartsAt))
            ->line('Vehicle: '.$this->vehicleName)
            ->line('Driver: '.$this->driverName)
            ->line('Amount due: '.$this->moneyLabel($this->amountMinor, $this->currency))
            ->line('No online payment has been collected.')
            ->action('View transfer booking', $this->viewUrl);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'airport_transfer_booking_confirmed',
            'reference' => $this->bookingReference,
            'title' => 'Airport transfer confirmed',
            'message' => 'Your driver and vehicle are reserved for '.$this->dateTimeLabel($this->serviceStartsAt).'.',
            'url' => $this->viewUrl,
        ];
    }
}
