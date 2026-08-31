<?php

namespace App\Contracts\Messaging;

/**
 * What came back from a send attempt.
 *
 * A failure is a value rather than an exception because the message has already
 * been written to the thread by the time it is sent: losing it to an unwound
 * transaction would hide from staff that they tried to reply at all.
 */
final class TransportResult
{
    private function __construct(
        public readonly bool $accepted,
        public readonly ?string $providerMessageId,
        public readonly ?string $failureReason,
    ) {}

    public static function accepted(?string $providerMessageId = null): self
    {
        return new self(true, $providerMessageId, null);
    }

    /**
     * The reason is shown to staff, so it must say what to do about it and must
     * never carry a credential or a raw provider response.
     */
    public static function failed(string $reason): self
    {
        return new self(false, null, mb_substr(trim($reason), 0, 255));
    }
}
