<?php

namespace App\Contracts\Messaging;

use App\Models\ConversationMessage;

/**
 * Sends a message out through a provider.
 *
 * Behind an interface so the domain never depends on a live account: the log
 * driver stands in for local work and the whole test suite, and a real adapter
 * is registered only when credentials exist.
 */
interface MessagingTransport
{
    public function name(): string;

    /** Whether this transport has everything it needs to actually send. */
    public function isConfigured(): bool;

    /**
     * Delivers the message.
     *
     * Returning a result rather than throwing keeps a provider outage from
     * losing the message: it stays in the thread, marked failed, and can be
     * retried.
     */
    public function send(ConversationMessage $message, string $to): TransportResult;

    /**
     * Verifies a webhook came from the provider.
     *
     * @param  array<string, string>  $headers
     */
    public function verifySignature(string $payload, array $headers): bool;

    /**
     * Reads a provider payload into the shape the domain understands.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null Null when the payload is one this
     *                                   transport has nothing to do with.
     */
    public function parseWebhook(array $payload): ?array;
}
