<?php

namespace App\Notifications\Finance;

use Illuminate\Notifications\Messages\MailMessage;

class ExpenseDecisionNotification extends FinanceMailNotification
{
    public function __construct(
        public readonly string $reference,
        public readonly string $description,
        public readonly string $amount,
        public readonly string $statusLabel,
        public readonly string $message,
    ) {
        parent::__construct();
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your expense claim: '.$this->statusLabel)
            ->greeting('Hello '.$notifiable->name.',')
            ->line($this->message)
            ->line('Claim: '.$this->reference.' — '.$this->description)
            ->line('Amount: '.$this->amount)
            ->action('View your claims', $this->expensesUrl());
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'expense_decision',
            'reference' => $this->reference,
            'title' => 'Expense claim: '.$this->statusLabel,
            'message' => $this->message,
            'url' => $this->expensesUrl(),
        ];
    }
}
