<?php

namespace App\Notifications\AirportTransfers;

use Illuminate\Notifications\Messages\MailMessage;

class AirportTransferBookingReceivedNotification extends AirportTransferMailNotification
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
        public readonly string $viewUrl,
    ) {
        parent::__construct();
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('PISFA received your airport transfer request')
            ->greeting($this->greeting($this->recipientName))
            ->line('We received your '.$this->transferLabel.' request.')
            ->line('Reference: '.$this->bookingReference)
            ->line('Route: '.$this->airportName.' — '.$this->locationName)
            ->line('Service time: '.$this->dateTimeLabel($this->serviceStartsAt))
            ->line('Amount due: '.$this->moneyLabel($this->amountMinor, $this->currency))
            ->line('This request is pending staff review. No online payment has been collected.')
            ->action('View transfer request', $this->viewUrl);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'airport_transfer_booking_received',
            'reference' => $this->bookingReference,
            'title' => 'Airport transfer request received',
            'message' => 'Your transfer request is pending review. No online payment has been collected.',
            'url' => $this->viewUrl,
        ];
    }
}
