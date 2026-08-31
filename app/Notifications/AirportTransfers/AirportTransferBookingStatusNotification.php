<?php

namespace App\Notifications\AirportTransfers;

use Illuminate\Notifications\Messages\MailMessage;

class AirportTransferBookingStatusNotification extends AirportTransferMailNotification
{
    public function __construct(
        public readonly string $recipientName,
        public readonly string $bookingReference,
        public readonly string $statusLabel,
        public readonly string $serviceStartsAt,
        public readonly string $viewUrl,
        public readonly ?string $customerReason = null,
    ) {
        parent::__construct();
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject('Your PISFA airport transfer is '.$this->statusLabel)
            ->greeting($this->greeting($this->recipientName))
            ->line('Your airport transfer request is now '.$this->statusLabel.'.')
            ->line('Reference: '.$this->bookingReference)
            ->line('Scheduled service time: '.$this->dateTimeLabel($this->serviceStartsAt));

        if (filled($this->customerReason)) {
            $message->line('Reason: '.$this->customerReason);
        }

        return $message
            ->line('No online payment was collected for this transfer.')
            ->action('View transfer request', $this->viewUrl);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'airport_transfer_booking_status',
            'reference' => $this->bookingReference,
            'title' => 'Airport transfer '.$this->statusLabel,
            'message' => 'Your airport transfer request is now '.$this->statusLabel.'.',
            'url' => $this->viewUrl,
        ];
    }
}
