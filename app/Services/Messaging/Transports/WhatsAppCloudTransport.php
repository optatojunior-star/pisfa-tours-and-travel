<?php

namespace App\Services\Messaging\Transports;

use App\Contracts\Messaging\MessagingTransport;
use App\Contracts\Messaging\TransportResult;
use App\Models\ConversationMessage;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Meta's WhatsApp Cloud API.
 *
 * Every credential is read from the environment at call time and none is ever
 * logged, returned in a failure reason, or written to an audit entry. The class
 * is registered only when it is configured, so an unconfigured deployment falls
 * back to the log transport instead of failing at the moment somebody tries to
 * reply to a customer.
 */
class WhatsAppCloudTransport implements MessagingTransport
{
    public function name(): string
    {
        return 'whatsapp_cloud';
    }

    public function isConfigured(): bool
    {
        return filled(config('messaging.whatsapp.token'))
            && filled(config('messaging.whatsapp.phone_number_id'))
            && filled(config('messaging.whatsapp.app_secret'));
    }

    public function send(ConversationMessage $message, string $to): TransportResult
    {
        if (! $this->isConfigured()) {
            return TransportResult::failed('WhatsApp is not configured on this deployment.');
        }

        $version = (string) config('messaging.whatsapp.api_version', 'v21.0');
        $phoneNumberId = (string) config('messaging.whatsapp.phone_number_id');

        try {
            $response = Http::withToken((string) config('messaging.whatsapp.token'))
                ->timeout((int) config('messaging.whatsapp.timeout_seconds', 15))
                ->asJson()
                ->post("https://graph.facebook.com/{$version}/{$phoneNumberId}/messages", [
                    'messaging_product' => 'whatsapp',
                    'recipient_type' => 'individual',
                    'to' => ltrim($to, '+'),
                    'type' => 'text',
                    'text' => ['preview_url' => false, 'body' => $message->body],
                ]);
        } catch (Throwable $exception) {
            // The exception message can carry the request, including the bearer
            // token, so it is deliberately not passed through.
            report($exception);

            return TransportResult::failed('WhatsApp could not be reached. The message has not been sent.');
        }

        if ($response->failed()) {
            // Only the provider's own error text is surfaced, never the body of
            // the request that produced it.
            $reason = (string) $response->json('error.message', 'WhatsApp rejected the message.');

            return TransportResult::failed($reason);
        }

        $providerId = $response->json('messages.0.id');

        return TransportResult::accepted(is_string($providerId) ? $providerId : null);
    }

    /**
     * @param  array<string, string>  $headers
     */
    public function verifySignature(string $payload, array $headers): bool
    {
        $secret = (string) config('messaging.whatsapp.app_secret', '');

        if ($secret === '') {
            return false;
        }

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

        $expected = hash_hmac('sha256', $payload, $secret);

        // Constant time: a timing-variable compare here leaks the signature a
        // byte at a time to anybody willing to send enough requests.
        return hash_equals($expected, substr($header, 7));
    }

    /**
     * Reads the Cloud API's nested payload.
     *
     * The shape is entry[] → changes[] → value → messages[]/statuses[], and a
     * single delivery may carry either inbound messages or delivery receipts.
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
                // A non-text message (an image, a location) has no body to
                // store. It is recorded as a placeholder so staff can see that
                // something arrived and open WhatsApp to look at it, rather
                // than the thread silently skipping a message.
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
}
