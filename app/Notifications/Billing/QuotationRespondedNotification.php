<?php

namespace App\Notifications\Billing;

use Illuminate\Notifications\Messages\MailMessage;

/**
 * Tells the team a customer has accepted or declined. Addressed to staff, never
 * to the customer, so it may carry the decline reason verbatim.
 */
class QuotationRespondedNotification extends BillingMailNotification
{
    public function __construct(
        public readonly string $number,
        public readonly string $title,
        public readonly string $contactName,
        public readonly bool $accepted,
        public readonly ?string $reason,
        public readonly string $consoleUrl,
    ) {
        parent::__construct();
    }

    public function toMail(object $notifiable): MailMessage
    {
        $verb = $this->accepted ? 'accepted' : 'declined';

        $message = (new MailMessage)
            ->subject('Quotation '.$this->number.' was '.$verb)
            ->greeting('Hello,')
            ->line($this->contactName.' has '.$verb.' quotation '.$this->number.' for '.$this->title.'.');

        if (! $this->accepted && $this->reason !== null) {
            $message->line('Reason given: '.$this->reason);
        }

        if ($this->accepted) {
            $message->line('Convert it to an invoice when you are ready to collect payment.');
        }

        return $message->action('Open in the console', $this->consoleUrl);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'quotation_responded',
            'reference' => $this->number,
            'title' => 'Quotation '.($this->accepted ? 'accepted' : 'declined'),
            'message' => $this->contactName.' responded to '.$this->number.'.',
            'url' => $this->consoleUrl,
        ];
    }
}
