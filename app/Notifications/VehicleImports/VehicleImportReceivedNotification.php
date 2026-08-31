<?php

namespace App\Notifications\VehicleImports;

use Illuminate\Notifications\Messages\MailMessage;

class VehicleImportReceivedNotification extends VehicleImportMailNotification
{
    public function __construct(
        public readonly string $recipientName,
        public readonly string $reference,
        public readonly string $vehicleSummary,
        public readonly int $units,
        public readonly string $budget,
        public readonly string $trackingUrl,
    ) {
        parent::__construct();
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('We received your PISFA vehicle import request')
            ->greeting($this->greeting($this->recipientName))
            ->line('Thank you. Our sourcing team will review your requirements and send a quotation.')
            ->line('Reference: '.$this->reference)
            ->line('Vehicle: '.$this->vehicleSummary.($this->units > 1 ? ' x'.$this->units : ''))
            ->line('Your budget: '.$this->budget)
            ->line('This is a sourcing request. Nothing is ordered and no payment is due until you accept a quotation.')
            ->action('Track your import', $this->trackingUrl)
            ->line('Keep this link private. It is how you track this import.');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'vehicle_import_received',
            'reference' => $this->reference,
            'title' => 'Import request received',
            'message' => 'We received your request for '.$this->vehicleSummary.'.',
            'url' => $this->trackingUrl,
        ];
    }
}
