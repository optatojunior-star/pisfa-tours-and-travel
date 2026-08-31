<?php

namespace App\Notifications\CarHire;

use Illuminate\Notifications\Messages\MailMessage;

class CarHireReturnReminderNotification extends CarHireMailNotification
{
    public function __construct(
        public readonly string $bookingReference,
        public readonly string $vehicleName,
        public readonly string $returnAt,
        public readonly string $returnLocation,
    ) {
        parent::__construct();
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Reminder: your PISFA vehicle return is approaching')
            ->greeting('Hello '.$notifiable->name.',')
            ->line('Your confirmed '.$this->vehicleName.' hire is due for return soon.')
            ->line('Return time: '.$this->dateTimeLabel($this->returnAt))
            ->line('Return location: '.$this->returnLocation)
            ->line('Contact PISFA promptly if you need operational assistance.')
            ->action('View hire booking', $this->bookingUrl($this->bookingReference));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'car_hire_return_reminder',
            'reference' => $this->bookingReference,
            'title' => 'Vehicle return reminder',
            'message' => $this->vehicleName.' is due '.$this->dateTimeLabel($this->returnAt).'.',
            'url' => $this->bookingUrl($this->bookingReference),
        ];
    }
}
