<?php

namespace App\Notifications\Leasing;

use Illuminate\Notifications\Messages\MailMessage;

class LeasePayoutReadyNotification extends LeasingMailNotification
{
    public function __construct(
        public readonly string $reference,
        public readonly string $leaseReference,
        public readonly string $period,
        public readonly string $net,
    ) {
        parent::__construct();
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your '.$this->period.' statement from PISFA')
            ->greeting('Hello '.$notifiable->name.',')
            ->line('Your statement for '.$this->period.' is ready.')
            ->line('Amount due to you: '.$this->net)
            ->line('Statement reference: '.$this->reference)
            ->line('The transfer follows separately. The statement shows what the vehicle earned and anything deducted.')
            ->action('View your statement', $this->leaseUrl($this->leaseReference));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'lease_payout_ready',
            'reference' => $this->reference,
            'title' => $this->period.' statement ready',
            'message' => $this->net.' is due to you for '.$this->period.'.',
            'url' => $this->leaseUrl($this->leaseReference),
        ];
    }
}
