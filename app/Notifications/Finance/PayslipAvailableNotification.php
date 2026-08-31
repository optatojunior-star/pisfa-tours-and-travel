<?php

namespace App\Notifications\Finance;

use Illuminate\Notifications\Messages\MailMessage;

/**
 * Tells somebody their payslip is ready.
 *
 * Deliberately carries the net figure and nothing else — no breakdown, no
 * deductions, no colleagues. Mail is not a private channel, and the payslip
 * itself lives behind the portal.
 */
class PayslipAvailableNotification extends FinanceMailNotification
{
    public function __construct(
        public readonly string $period,
        public readonly string $net,
        public readonly string $runReference,
    ) {
        parent::__construct();
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your '.$this->period.' payslip is ready')
            ->greeting('Hello '.$notifiable->name.',')
            ->line('Your payslip for '.$this->period.' is available in the portal.')
            ->line('Net pay: '.$this->net)
            ->action('View your payslip', $this->payslipsUrl());
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'payslip_available',
            'reference' => $this->runReference,
            'title' => $this->period.' payslip ready',
            'message' => 'Your net pay for '.$this->period.' is '.$this->net.'.',
            'url' => $this->payslipsUrl(),
        ];
    }
}
