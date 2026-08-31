<?php

namespace Tests\Support;

use App\Contracts\Messaging\MessagingTransport;
use App\Contracts\Messaging\TransportResult;
use App\Models\ConversationMessage;

/**
 * A WhatsApp transport for tests.
 *
 * Exists so the suite can exercise sending, delivery receipts, and signature
 * verification without a WhatsApp Business account or any real credential. The
 * signing secret below is a fixed test string, never read from configuration,
 * and never used anywhere a real deployment can reach it.
 */
class FakeMessagingTransport implements MessagingTransport
{
    public const SECRET = 'test-signing-secret';

    /** @var list<array{message_id: int, to: string, body: string}> */
    public array $sent = [];

    public bool $shouldFail = false;

    public string $failureReason = 'The provider rejected the message.';

    public int $counter = 0;

    public function name(): string
    {
        return 'fake_whatsapp';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function send(ConversationMessage $message, string $to): TransportResult
    {
        if ($this->shouldFail) {
            return TransportResult::failed($this->failureReason);
        }

        $this->sent[] = [
            'message_id' => (int) $message->getKey(),
            'to' => $to,
            'body' => $message->body,
        ];

        return TransportResult::accepted('wamid.test.'.(++$this->counter));
    }

    /** @param array<string, string> $headers */
    public function verifySignature(string $payload, array $headers): bool
    {
        $header = '';

        foreach ($headers as $name => $value) {
            if (strtolower($name) === 'x-hub-signature-256') {
                $header = $value;

                break;
            }
        }

        if (! str_starts_with($header, 'sha256=')) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $payload, self::SECRET), substr($header, 7));
    }

    /**
     * Mirrors the real Cloud API payload shape, so a test that passes here is
     * exercising the same parsing the live adapter does.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    public function parseWebhook(array $payload): ?array
    {
        $value = data_get($payload, 'entry.0.changes.0.value');

        if (! is_array($value)) {
            return null;
        }

        $inbound = data_get($value, 'messages.0');

        if (is_array($inbound)) {
            $body = data_get($inbound, 'text.body');

            return [
                'kind' => 'inbound',
                'event_id' => (string) data_get($inbound, 'id', ''),
                'from' => (string) data_get($inbound, 'from', ''),
                'name' => (string) data_get($value, 'contacts.0.profile.name', 'WhatsApp contact'),
                'body' => is_string($body) && trim($body) !== ''
                    ? $body
                    : '[Attachment sent on WhatsApp — open the WhatsApp Business inbox to view it.]',
            ];
        }

        $status = data_get($value, 'statuses.0');

        if (is_array($status)) {
            return [
                'kind' => 'status',
                'event_id' => (string) data_get($status, 'id', '').':'.(string) data_get($status, 'status', ''),
                'provider_message_id' => (string) data_get($status, 'id', ''),
                'status' => (string) data_get($status, 'status', ''),
                'failure_reason' => data_get($status, 'errors.0.title'),
            ];
        }

        return null;
    }

    /** Builds the header a signed delivery would carry. */
    public static function signatureFor(string $payload): string
    {
        return 'sha256='.hash_hmac('sha256', $payload, self::SECRET);
    }
}
