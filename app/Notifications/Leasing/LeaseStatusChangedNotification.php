<?php

namespace App\Notifications\Leasing;

use Illuminate\Notifications\Messages\MailMessage;

class LeaseStatusChangedNotification extends LeasingMailNotification
{
    public function __construct(
        public readonly string $reference,
        public readonly string $statusLabel,
        public readonly string $termsSummary,
        public readonly string $message,
    ) {
        parent::__construct();
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your PISFA vehicle lease: '.$this->statusLabel)
            ->greeting('Hello '.$notifiable->name.',')
            ->line($this->message)
            ->line('Lease: '.$this->reference)
            ->line('Terms: '.$this->termsSummary)
            ->action('View your lease', $this->leaseUrl($this->reference));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'vehicle_lease_status_changed',
            'reference' => $this->reference,
            'title' => 'Your lease: '.$this->statusLabel,
            'message' => $this->message,
            'url' => $this->leaseUrl($this->reference),
        ];
    }
}
