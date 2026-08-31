<?php

namespace App\Notifications\CarHire;

use Illuminate\Notifications\Messages\MailMessage;

class CarHireDriverAssignmentNotification extends CarHireMailNotification
{
    public function __construct(
        public readonly string $bookingReference,
        public readonly string $vehicleName,
        public readonly string $pickupAt,
        public readonly bool $assigned,
        public readonly bool $forDriver,
        public readonly ?string $counterpartName = null,
        public readonly ?string $counterpartPhone = null,
        public readonly ?string $reason = null,
    ) {
        parent::__construct();
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject($this->assigned ? 'PISFA vehicle hire driver assigned' : 'PISFA vehicle hire assignment changed')
            ->greeting('Hello '.$notifiable->name.',')
            ->line($this->assignmentMessage())
            ->line('Reference: '.$this->bookingReference)
            ->line('Pickup: '.$this->dateTimeLabel($this->pickupAt));

        if (filled($this->counterpartName)) {
            $message->line(($this->forDriver ? 'Customer: ' : 'Driver: ').$this->counterpartName);
        }

        if (filled($this->counterpartPhone)) {
            $message->line('Contact telephone: '.$this->counterpartPhone);
        }

        if (filled($this->reason)) {
            $message->line('Reason: '.$this->reason);
        }

        return $message->action(
            $this->forDriver ? 'Open dashboard' : 'View hire booking',
            $this->forDriver ? $this->dashboardUrl() : $this->bookingUrl($this->bookingReference),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => $this->assigned ? 'car_hire_driver_assigned' : 'car_hire_driver_unassigned',
            'reference' => $this->bookingReference,
            'title' => $this->assigned ? 'Vehicle hire driver assigned' : 'Vehicle hire assignment changed',
            'message' => $this->assignmentMessage(),
            'url' => $this->forDriver ? $this->dashboardUrl() : $this->bookingUrl($this->bookingReference),
        ];
    }

    private function assignmentMessage(): string
    {
        if ($this->assigned) {
            return $this->forDriver
                ? 'You have been assigned to the '.$this->vehicleName.' hire.'
                : 'A PISFA driver has been assigned to your '.$this->vehicleName.' hire.';
        }

        return $this->forDriver
            ? 'You are no longer assigned to the '.$this->vehicleName.' hire.'
            : 'The previous driver assignment for your '.$this->vehicleName.' hire was removed.';
    }
}
