<?php

namespace App\Notifications\Fleet;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\Middleware\RateLimited;

/**
 * One digest per sweep rather than a message per vehicle.
 *
 * A fleet of thirty vehicles with lapsed insurance would otherwise produce
 * thirty emails, which is how alerts get filtered into a folder nobody reads.
 */
class FleetAlertNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 60;

    public int $maxExceptions = 3;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    /**
     * @param  list<array{vehicle: string, plate: string, reason: string}>  $serviceDue
     * @param  list<array{vehicle: string, plate: string, document: string, expires: string, expired: bool}>  $documents
     * @param  list<array{driver: string, expires: string, expired: bool}>  $licences
     */
    public function __construct(
        public readonly array $serviceDue,
        public readonly array $documents,
        public readonly string $consoleUrl,
        public readonly array $licences = [],
    ) {
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    /** @return list<RateLimited> */
    public function middleware(object $notifiable, string $channel): array
    {
        return $channel === 'mail' ? [new RateLimited('fleet-notification-mail')] : [];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $expired = array_merge(
            array_filter($this->documents, static fn (array $row): bool => $row['expired']),
            array_filter($this->licences, static fn (array $row): bool => $row['expired']),
        );

        $message = (new MailMessage)
            ->subject($expired === []
                ? 'PISFA fleet: attention needed'
                : 'PISFA fleet: expired paperwork')
            ->greeting('Hello '.$notifiable->name.',');

        if ($expired !== []) {
            // A vehicle on the road without valid cover is the one item here
            // that is not merely a scheduling matter.
            $message->line('**'.count($expired).' compliance item(s) have already expired.**');
        }

        if ($this->documents !== []) {
            $message->line('Paperwork expiring or expired:');

            foreach ($this->documents as $row) {
                $message->line('• '.$row['vehicle'].' ('.$row['plate'].') — '
                    .$row['document'].' '
                    .($row['expired'] ? 'expired' : 'expires').' '.$row['expires']);
            }
        }

        if ($this->licences !== []) {
            $message->line('Driver licences:');

            foreach ($this->licences as $row) {
                $message->line('• '.$row['driver'].' — licence '
                    .($row['expired'] ? 'expired' : 'expires').' '.$row['expires']);
            }
        }

        if ($this->serviceDue !== []) {
            $message->line('Service due:');

            foreach ($this->serviceDue as $row) {
                $message->line('• '.$row['vehicle'].' ('.$row['plate'].') — '.$row['reason']);
            }
        }

        return $message->action('Open the fleet console', $this->consoleUrl);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'fleet_alert',
            'title' => 'Fleet attention needed',
            'message' => count($this->serviceDue).' service due, '
                .count($this->documents).' document(s) and '
                .count($this->licences).' licence(s) expiring.',
            'url' => $this->consoleUrl,
        ];
    }
}
