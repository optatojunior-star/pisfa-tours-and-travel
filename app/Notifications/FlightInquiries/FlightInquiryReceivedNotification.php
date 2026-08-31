<?php

namespace App\Notifications\FlightInquiries;

use Illuminate\Notifications\Messages\MailMessage;

class FlightInquiryReceivedNotification extends FlightInquiryMailNotification
{
    public function __construct(
        public readonly string $recipientName,
        public readonly string $inquiryReference,
        public readonly string $scopeLabel,
        public readonly string $routeLabel,
        public readonly string $outboundOn,
        public readonly ?string $returnOn,
        public readonly int $passengerCount,
        public readonly string $travelClassLabel,
        public readonly string $viewUrl,
    ) {
        parent::__construct();
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject('We received your PISFA flight enquiry')
            ->greeting($this->greeting($this->recipientName))
            ->line('Thank you for your '.mb_strtolower($this->scopeLabel).' enquiry. A travel consultant will come back to you with fare options.')
            ->line('Reference: '.$this->inquiryReference)
            ->line('Route: '.$this->routeLabel)
            ->line('Outbound: '.$this->dateLabel($this->outboundOn));

        if ($this->returnOn !== null) {
            $message->line('Return: '.$this->dateLabel($this->returnOn));
        }

        return $message
            ->line('Travellers: '.$this->passengerCount.' in '.mb_strtolower($this->travelClassLabel).' class')
            ->line('This is an enquiry, not a reservation. No seats are held and no payment has been collected.')
            ->action('View enquiry', $this->viewUrl);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'flight_inquiry_received',
            'reference' => $this->inquiryReference,
            'title' => 'Flight enquiry received',
            'message' => 'We received your enquiry for '.$this->routeLabel.'.',
            'url' => $this->viewUrl,
        ];
    }
}
