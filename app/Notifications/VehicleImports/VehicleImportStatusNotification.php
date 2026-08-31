<?php

namespace App\Notifications\VehicleImports;

use Illuminate\Notifications\Messages\MailMessage;

class VehicleImportStatusNotification extends VehicleImportMailNotification
{
    public function __construct(
        public readonly string $recipientName,
        public readonly string $reference,
        public readonly string $vehicleSummary,
        public readonly string $statusLabel,
        public readonly string $statusDescription,
        public readonly string $trackingUrl,
        public readonly ?string $reason = null,
    ) {
        parent::__construct();
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject('PISFA import update: '.$this->statusLabel)
            ->greeting($this->greeting($this->recipientName))
            ->line('Your import of '.$this->vehicleSummary.' is now '.mb_strtolower($this->statusLabel).'.')
            ->line($this->statusDescription)
            ->line('Reference: '.$this->reference);

        if (filled($this->reason)) {
            $message->line('Note from our team: '.$this->reason);
        }

        return $message->action('Track your import', $this->trackingUrl);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'vehicle_import_status',
            'reference' => $this->reference,
            'title' => 'Import '.mb_strtolower($this->statusLabel),
            'message' => $this->statusDescription,
            'url' => $this->trackingUrl,
        ];
    }
}
