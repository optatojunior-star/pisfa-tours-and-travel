<?php

namespace App\Notifications\AirportTransfers;

use Illuminate\Notifications\Messages\MailMessage;

class AirportTransferTeamAssignmentNotification extends AirportTransferMailNotification
{
    public function __construct(
        public readonly string $recipientName,
        public readonly string $bookingReference,
        public readonly string $serviceStartsAt,
        public readonly string $airportName,
        public readonly string $locationName,
        public readonly string $vehicleName,
        public readonly bool $assigned,
        public readonly bool $forDriver,
        public readonly string $viewUrl,
        public readonly ?string $driverName = null,
        public readonly ?string $contactName = null,
        public readonly ?string $contactPhone = null,
        public readonly ?string $serviceAddress = null,
        public readonly ?string $reason = null,
    ) {
        parent::__construct();
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject($this->assigned ? 'PISFA airport transfer team assigned' : 'PISFA airport transfer assignment changed')
            ->greeting($this->greeting($this->recipientName))
            ->line($this->assignmentMessage())
            ->line('Reference: '.$this->bookingReference)
            ->line('Service time: '.$this->dateTimeLabel($this->serviceStartsAt))
            ->line('Route: '.$this->airportName.' — '.$this->locationName)
            ->line('Vehicle: '.$this->vehicleName);

        if (! $this->forDriver && filled($this->driverName)) {
            $message->line('Driver: '.$this->driverName);
        }

        if ($this->forDriver && filled($this->contactName)) {
            $message->line('Passenger contact: '.$this->contactName);
        }

        if ($this->forDriver && filled($this->contactPhone)) {
            $message->line('Contact telephone: '.$this->contactPhone);
        }

        if ($this->forDriver && filled($this->serviceAddress)) {
            $message->line('Service address: '.$this->serviceAddress);
        }

        if (filled($this->reason)) {
            $message->line('Reason: '.$this->reason);
        }

        return $message->action(
            $this->forDriver ? 'Open dashboard' : 'View transfer booking',
            $this->viewUrl,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => $this->assigned ? 'airport_transfer_team_assigned' : 'airport_transfer_team_unassigned',
            'reference' => $this->bookingReference,
            'title' => $this->assigned ? 'Airport transfer team assigned' : 'Airport transfer assignment changed',
            'message' => $this->assignmentMessage(),
            'url' => $this->viewUrl,
        ];
    }

    private function assignmentMessage(): string
    {
        if ($this->assigned) {
            return $this->forDriver
                ? 'You have been assigned to this airport transfer.'
                : 'A PISFA driver and vehicle have been reserved for your transfer.';
        }

        return $this->forDriver
            ? 'You are no longer assigned to this airport transfer.'
            : 'The previous driver and vehicle assignment has been released.';
    }
}
