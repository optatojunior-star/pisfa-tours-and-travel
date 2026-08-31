<?php

namespace App\Notifications\Accommodation;

use Illuminate\Notifications\Messages\MailMessage;

class PropertyArrivalReminderNotification extends AccommodationMailNotification
{
    public function __construct(
        public readonly string $bookingReference,
        public readonly string $propertyName,
        public readonly string $stay,
        public readonly string $checkInFrom,
        public readonly string $roomSummary,
        public readonly ?string $directions,
    ) {
        parent::__construct();
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject('Your stay at '.$this->propertyName.' is coming up')
            ->greeting('Hello '.$notifiable->name.',')
            ->line('This is a reminder about your stay at '.$this->propertyName.'.')
            ->line('Reference: '.$this->bookingReference)
            ->line('Dates: '.$this->stay)
            ->line('Rooms: '.$this->roomSummary)
            ->line('Check in from '.$this->checkInFrom.'.');

        if ($this->directions !== null) {
            $message->line('Getting there: '.$this->directions);
        }

        return $message->action('View your stay', $this->bookingUrl($this->bookingReference));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'property_arrival_reminder',
            'reference' => $this->bookingReference,
            'title' => 'Your stay is coming up',
            'message' => 'You are due at '.$this->propertyName.' — '.$this->stay.'.',
            'url' => $this->bookingUrl($this->bookingReference),
        ];
    }
}
