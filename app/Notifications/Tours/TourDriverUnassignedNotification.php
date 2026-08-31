<?php

namespace App\Notifications\Tours;

use Illuminate\Notifications\Messages\MailMessage;

class TourDriverUnassignedNotification extends TourMailNotification
{
    public function __construct(
        public readonly string $bookingReference,
        public readonly string $tourName,
        public readonly string $departureStartsAt,
        public readonly ?string $reason = null,
        public readonly bool $forDriver = true,
    ) {
        parent::__construct();
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject('PISFA tour assignment changed')
            ->greeting('Hello '.$notifiable->name.',')
            ->line($this->forDriver
                ? 'You are no longer assigned to '.$this->tourName.'.'
                : 'The previous driver assignment for '.$this->tourName.' has been removed.')
            ->line('Reference: '.$this->bookingReference)
            ->line('Departure: '.$this->departureLabel($this->departureStartsAt));

        if (filled($this->reason)) {
            $message->line('Reason: '.$this->reason);
        }

        return $message
            ->line($this->forDriver
                ? 'Your driver workspace will no longer show this assignment.'
                : 'PISFA will notify you when another driver is assigned.')
            ->action(
                $this->forDriver ? 'Open dashboard' : 'View booking',
                $this->forDriver ? $this->dashboardUrl() : $this->bookingUrl($this->bookingReference),
            );
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => $this->forDriver ? 'tour_driver_unassigned' : 'tour_driver_removed',
            'reference' => $this->bookingReference,
            'title' => 'Tour assignment changed',
            'message' => $this->forDriver
                ? 'You are no longer assigned to '.$this->tourName.'.'
                : 'The driver assignment for '.$this->tourName.' has been removed.',
            'url' => $this->forDriver ? $this->dashboardUrl() : $this->bookingUrl($this->bookingReference),
        ];
    }
}
