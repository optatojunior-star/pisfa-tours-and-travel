<?php

namespace App\Services;

use App\Models\StaffInvitation;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class StaffInvitationNotification extends Notification
{
    public function __construct(
        public readonly StaffInvitation $invitation,
        private readonly string $rawToken,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $expiresAt = $this->invitation->expires_at
            ->timezone(config('pisfa.business_timezone', 'Africa/Kampala'))
            ->format('j F Y \a\t g:i A T');

        return (new MailMessage)
            ->subject('Your PISFA team invitation')
            ->greeting('Hello '.$notifiable->name.',')
            ->line('You have been invited to join the PISFA Tours and Travels operations team.')
            ->line('This private invitation expires on '.$expiresAt.'.')
            ->action('Set up my account', $this->invitationUrl())
            ->line('If you were not expecting this invitation, you can safely ignore this email.');
    }

    public function invitationUrl(): string
    {
        $relativeUrl = route(
            'staff-invitations.show',
            ['token' => $this->rawToken],
            absolute: false,
        );

        return rtrim((string) config('app.url'), '/').'/'.ltrim($relativeUrl, '/');
    }
}
