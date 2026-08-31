<?php

namespace App\Notifications\Leasing;

use Illuminate\Notifications\Messages\MailMessage;

class LeaseApplicationUpdatedNotification extends LeasingMailNotification
{
    public function __construct(
        public readonly string $reference,
        public readonly string $vehicleLabel,
        public readonly string $statusLabel,
        public readonly string $message,
    ) {
        parent::__construct();
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Update on your '.$this->vehicleLabel.' leasing offer')
            ->greeting('Hello,')
            ->line($this->message)
            ->line('Reference: '.$this->reference)
            ->line('Status: '.$this->statusLabel)
            ->action('Track your offer', $this->applicationUrl($this->reference));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'lease_application_updated',
            'reference' => $this->reference,
            'title' => 'Leasing offer: '.$this->statusLabel,
            'message' => $this->message,
            'url' => $this->applicationUrl($this->reference),
        ];
    }
}
