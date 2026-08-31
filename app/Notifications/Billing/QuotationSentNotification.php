<?php

namespace App\Notifications\Billing;

use Illuminate\Notifications\Messages\MailMessage;

class QuotationSentNotification extends BillingMailNotification
{
    public function __construct(
        public readonly string $recipientName,
        public readonly string $number,
        public readonly string $title,
        public readonly string $total,
        public readonly ?string $validUntil,
        public readonly int $revision,
        public readonly string $viewUrl,
    ) {
        parent::__construct();
    }

    public function toMail(object $notifiable): MailMessage
    {
        $isRevision = $this->revision > 1;

        $message = (new MailMessage)
            ->subject($isRevision
                ? 'Revised PISFA quotation '.$this->number
                : 'Your PISFA quotation '.$this->number)
            ->greeting($this->salutation($this->recipientName))
            ->line($isRevision
                ? 'We have revised your quotation for '.$this->title.'. This replaces the previous version.'
                : 'Your quotation for '.$this->title.' is ready.')
            ->line('Quotation: '.$this->number.($isRevision ? ' (revision '.$this->revision.')' : ''))
            ->line('Total: '.$this->total);

        if ($this->validUntil !== null) {
            $message->line('This offer stands until '.$this->dateLabel($this->validUntil).'.');
        }

        return $message
            ->line('Accepting it produces an invoice; nothing is charged until then.')
            ->action('Review the quotation', $this->viewUrl);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'quotation_sent',
            'reference' => $this->number,
            'title' => $this->revision > 1 ? 'Revised quotation' : 'Quotation ready',
            'message' => $this->total.' for '.$this->title.'.',
            'url' => $this->viewUrl,
        ];
    }
}
