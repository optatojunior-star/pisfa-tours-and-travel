<?php

namespace App\Notifications\Leasing;

use Illuminate\Notifications\Messages\MailMessage;

class LeaseApplicationReceivedNotification extends LeasingMailNotification
{
    public function __construct(
        public readonly string $reference,
        public readonly string $vehicleLabel,
    ) {
        parent::__construct();
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('PISFA received your vehicle leasing offer')
            ->greeting('Hello,')
            ->line('Thank you for offering your '.$this->vehicleLabel.' to the PISFA fleet.')
            ->line('Reference: '.$this->reference)
            ->line('We will look at the details and come back to you to arrange an inspection. Nothing is agreed until you have seen and signed the terms.')
            ->action('Track your offer', $this->applicationUrl($this->reference));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'lease_application_received',
            'reference' => $this->reference,
            'title' => 'Leasing offer received',
            'message' => 'We have your offer of the '.$this->vehicleLabel.' and will be in touch to arrange an inspection.',
            'url' => $this->applicationUrl($this->reference),
        ];
    }
}
