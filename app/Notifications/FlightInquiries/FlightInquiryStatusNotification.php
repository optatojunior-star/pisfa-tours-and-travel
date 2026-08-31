<?php

namespace App\Notifications\FlightInquiries;

use Illuminate\Notifications\Messages\MailMessage;

class FlightInquiryStatusNotification extends FlightInquiryMailNotification
{
    public function __construct(
        public readonly string $recipientName,
        public readonly string $inquiryReference,
        public readonly string $statusLabel,
        public readonly string $routeLabel,
        public readonly string $outboundOn,
        public readonly string $viewUrl,
        public readonly ?string $travellerReason = null,
    ) {
        parent::__construct();
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject('Your PISFA flight enquiry is '.mb_strtolower($this->statusLabel))
            ->greeting($this->greeting($this->recipientName))
            ->line('Your flight enquiry is now '.mb_strtolower($this->statusLabel).'.')
            ->line('Reference: '.$this->inquiryReference)
            ->line('Route: '.$this->routeLabel)
            ->line('Outbound: '.$this->dateLabel($this->outboundOn));

        if (filled($this->travellerReason)) {
            $message->line('Note from our team: '.$this->travellerReason);
        }

        return $message
            ->line('No payment has been collected through this enquiry.')
            ->action('View enquiry', $this->viewUrl);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'flight_inquiry_status',
            'reference' => $this->inquiryReference,
            'title' => 'Flight enquiry '.mb_strtolower($this->statusLabel),
            'message' => 'Your enquiry for '.$this->routeLabel.' is now '.mb_strtolower($this->statusLabel).'.',
            'url' => $this->viewUrl,
        ];
    }
}
