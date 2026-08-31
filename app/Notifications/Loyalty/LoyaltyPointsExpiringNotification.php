<?php

namespace App\Notifications\Loyalty;

use Illuminate\Notifications\Messages\MailMessage;

class LoyaltyPointsExpiringNotification extends LoyaltyMailNotification
{
    public function __construct(
        public readonly string $recipientName,
        public readonly int $points,
        public readonly string $value,
        public readonly string $expiresOn,
    ) {
        parent::__construct();
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject('Your PISFA loyalty points expire soon')
            ->greeting($this->greeting($this->recipientName))
            ->line('You have '.number_format($this->points).' points, worth '.$this->value.'.');

        if ($this->expiresOn !== '') {
            $message->line('They expire on '.$this->dateLabel($this->expiresOn).' unless you book or redeem before then.');
        }

        return $message
            ->line('Any booking keeps your points alive for another cycle.')
            ->action('View your loyalty balance', route('portal.loyalty.index'));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'loyalty_points_expiring',
            'title' => 'Loyalty points expiring soon',
            'message' => number_format($this->points).' points expire soon.',
            'url' => route('portal.loyalty.index'),
        ];
    }
}
