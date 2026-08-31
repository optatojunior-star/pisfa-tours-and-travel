<?php

namespace App\Notifications\Billing;

use Illuminate\Notifications\Messages\MailMessage;

class QuotationRequestReceivedNotification extends BillingMailNotification
{
    public function __construct(
        public readonly string $recipientName,
        public readonly string $reference,
        public readonly string $serviceLabel,
        public readonly string $trackingUrl,
    ) {
        parent::__construct();
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('We have your PISFA quotation request')
            ->greeting($this->salutation($this->recipientName))
            ->line('Thank you for asking PISFA to quote for '.$this->serviceLabel.'.')
            ->line('Reference: '.$this->reference)
            ->line('A member of the team will price this and send you a written quotation. No payment is due at this stage.')
            ->action('Follow your request', $this->trackingUrl);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'quotation_request_received',
            'reference' => $this->reference,
            'title' => 'Quotation request received',
            'message' => 'We are pricing your '.$this->serviceLabel.' request.',
            'url' => $this->trackingUrl,
        ];
    }
}
