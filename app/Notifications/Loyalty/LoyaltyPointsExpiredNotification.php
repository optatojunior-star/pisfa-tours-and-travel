<?php

namespace App\Notifications\Loyalty;

use Illuminate\Notifications\Messages\MailMessage;

class LoyaltyPointsExpiredNotification extends LoyaltyMailNotification
{
    public function __construct(
        public readonly string $recipientName,
        public readonly int $points,
    ) {
        parent::__construct();
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your PISFA loyalty points have expired')
            ->greeting($this->greeting($this->recipientName))
            ->line(number_format($this->points).' points expired after a long period without activity.')
            ->line('Your tier is based on lifetime points earned, so your standing is unchanged.')
            ->line('You start earning again with your next booking.')
            ->action('View your loyalty balance', route('portal.loyalty.index'));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'loyalty_points_expired',
            'title' => 'Loyalty points expired',
            'message' => number_format($this->points).' points expired.',
            'url' => route('portal.loyalty.index'),
        ];
    }
}
