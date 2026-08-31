<?php

namespace App\Notifications\Billing;

use Illuminate\Notifications\Messages\MailMessage;

class InvoiceIssuedNotification extends BillingMailNotification
{
    public function __construct(
        public readonly string $recipientName,
        public readonly string $number,
        public readonly string $title,
        public readonly string $total,
        public readonly ?string $dueOn,
        public readonly string $viewUrl,
    ) {
        parent::__construct();
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject('PISFA invoice '.$this->number)
            ->greeting($this->salutation($this->recipientName))
            ->line('Your invoice for '.$this->title.' is ready.')
            ->line('Invoice: '.$this->number)
            ->line('Amount due: '.$this->total);

        if ($this->dueOn !== null) {
            $message->line('Payment is due by '.$this->dateLabel($this->dueOn).'.');
        }

        return $message->action('View and pay', $this->viewUrl);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'invoice_issued',
            'reference' => $this->number,
            'title' => 'Invoice issued',
            'message' => $this->total.' due for '.$this->title.'.',
            'url' => $this->viewUrl,
        ];
    }
}
