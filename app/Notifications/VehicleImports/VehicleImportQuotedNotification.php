<?php

namespace App\Notifications\VehicleImports;

use Illuminate\Notifications\Messages\MailMessage;

class VehicleImportQuotedNotification extends VehicleImportMailNotification
{
    public function __construct(
        public readonly string $recipientName,
        public readonly string $reference,
        public readonly string $vehicleSummary,
        public readonly string $totalPrice,
        public readonly string $deposit,
        public readonly ?string $expiresAt,
        public readonly string $trackingUrl,
    ) {
        parent::__construct();
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject('Your PISFA vehicle import quotation')
            ->greeting($this->greeting($this->recipientName))
            ->line('Your quotation for '.$this->vehicleSummary.' is ready.')
            ->line('Reference: '.$this->reference)
            ->line('Total price: '.$this->totalPrice)
            ->line('Deposit to begin sourcing: '.$this->deposit);

        if ($this->expiresAt !== null) {
            $message->line('This quotation is valid until '.$this->dateLabel($this->expiresAt).'.');
        }

        return $message
            ->line('Sourcing begins once the deposit is received. The balance is due when the vehicle is ready for delivery.')
            ->action('View and pay the deposit', $this->trackingUrl);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'vehicle_import_quoted',
            'reference' => $this->reference,
            'title' => 'Import quotation ready',
            'message' => $this->totalPrice.' for '.$this->vehicleSummary.'.',
            'url' => $this->trackingUrl,
        ];
    }
}
