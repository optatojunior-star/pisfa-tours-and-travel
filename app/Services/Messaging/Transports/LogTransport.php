<?php

namespace App\Services\Messaging\Transports;

use App\Contracts\Messaging\MessagingTransport;
use App\Contracts\Messaging\TransportResult;
use App\Models\ConversationMessage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The default WhatsApp transport: writes to the log instead of sending.
 *
 * This is what makes the domain testable without a WhatsApp Business account,
 * and what keeps a misconfigured production deployment from silently pretending
 * to have sent something — the log line says plainly that nothing left.
 */
class LogTransport implements MessagingTransport
{
    public function name(): string
    {
        return 'log';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function send(ConversationMessage $message, string $to): TransportResult
    {
        // The recipient is partially masked and the body is not logged at all.
        // A log file is read by more people than a conversation is, and it
        // outlives the message's own retention.
        Log::channel(config('messaging.log_channel', 'stack'))->info('WhatsApp message not sent: log transport.', [
            'conversation_message_id' => $message->getKey(),
            'to' => $this->mask($to),
            'characters' => mb_strlen($message->body),
        ]);

        return TransportResult::accepted('log-'.Str::lower((string) Str::ulid()));
    }

    public function verifySignature(string $payload, array $headers): bool
    {
        // Nothing signs a log. Inbound webhooks are refused rather than
        // waved through, so a deployment that forgot to configure a real
        // transport cannot be fed messages by anybody who finds the URL.
        return false;
    }

    public function parseWebhook(array $payload): ?array
    {
        return null;
    }

    private function mask(string $to): string
    {
        return mb_strlen($to) <= 4 ? '****' : str_repeat('*', mb_strlen($to) - 4).mb_substr($to, -4);
    }
}
