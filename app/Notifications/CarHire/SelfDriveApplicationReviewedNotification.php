<?php

namespace App\Notifications\CarHire;

use App\Enums\SelfDriveApplicationStatus;
use Illuminate\Notifications\Messages\MailMessage;

class SelfDriveApplicationReviewedNotification extends CarHireMailNotification
{
    public function __construct(
        public readonly string $bookingReference,
        public readonly string $vehicleName,
        public readonly SelfDriveApplicationStatus $status,
        public readonly ?string $reason,
    ) {
        parent::__construct();
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject('Your PISFA self-drive application was reviewed')
            ->greeting('Hello '.$notifiable->name.',')
            ->line('The self-drive application for '.$this->vehicleName.' is now '.$this->status->label().'.');

        if (filled($this->reason)) {
            $message->line('Review note: '.$this->reason);
        }

        return $message->action('View application', $this->bookingUrl($this->bookingReference));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'self_drive_application_reviewed',
            'reference' => $this->bookingReference,
            'title' => 'Self-drive application reviewed',
            'message' => 'Your application is '.$this->status->label().'.'.($this->reason ? ' '.$this->reason : ''),
            'url' => $this->bookingUrl($this->bookingReference),
        ];
    }
}
