<?php

namespace App\Notifications\FlightInquiries;

use Illuminate\Notifications\Messages\MailMessage;

class FlightInquiryAssignedNotification extends FlightInquiryMailNotification
{
    public function __construct(
        public readonly string $recipientName,
        public readonly string $inquiryReference,
        public readonly string $routeLabel,
        public readonly string $outboundOn,
        public readonly int $passengerCount,
        public readonly string $statusLabel,
        public readonly string $viewUrl,
        public readonly ?string $reason = null,
    ) {
        parent::__construct();
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject('A PISFA flight enquiry is assigned to you')
            ->greeting($this->greeting($this->recipientName))
            ->line('You are now the consultant for this flight enquiry.')
            ->line('Reference: '.$this->inquiryReference)
            ->line('Route: '.$this->routeLabel)
            ->line('Outbound: '.$this->dateLabel($this->outboundOn))
            ->line('Travellers: '.$this->passengerCount)
            ->line('Current status: '.$this->statusLabel);

        if (filled($this->reason)) {
            $message->line('Handover note: '.$this->reason);
        }

        return $message->action('Open the enquiry', $this->viewUrl);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'flight_inquiry_assigned',
            'reference' => $this->inquiryReference,
            'title' => 'Flight enquiry assigned',
            'message' => $this->routeLabel.' is assigned to you.',
            'url' => $this->viewUrl,
        ];
    }
}
