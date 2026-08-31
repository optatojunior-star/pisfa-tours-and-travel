<?php

namespace App\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Strips secrets out of every log record.
 *
 * Applied as a Monolog processor rather than left to call sites. Asking every
 * `Log::info()` in the codebase to remember what is sensitive is a rule that
 * holds until the first person in a hurry, and a logged token is a token that
 * has to be rotated. Doing it here means it also covers the framework's own
 * logging, and any code written later.
 *
 * Matching is by key *name*, and by substring, so `stripe_secret_key`,
 * `WHATSAPP_ACCESS_TOKEN` and `card[number]` are all caught without anybody
 * maintaining an exhaustive list.
 */
class RedactSensitiveValues implements ProcessorInterface
{
    public const REDACTED = '[redacted]';

    /**
     * Substrings that make a key sensitive.
     *
     * `signature` is here because a webhook signature is a valid credential for
     * replaying that request. `phone` and `email` are not: an operator needs to
     * be able to trace a booking, and those already appear in the database the
     * same person can read.
     *
     * @var list<string>
     */
    private const SENSITIVE_KEYS = [
        'password',
        'secret',
        'token',
        'api_key',
        'apikey',
        'authorization',
        'auth',
        'credential',
        'private_key',
        'signature',
        'cvv',
        'cvc',
        'card_number',
        'cardnumber',
        'pan',
        'iban',
        'account_number',
        'session',
        'cookie',
        'otp',
        'two_factor',
        'recovery_code',
        'app_key',
        'idempotency_owner_hash',
    ];

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            context: $this->redact($record->context),
            extra: $this->redact($record->extra),
        );
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private function redact(array $values, int $depth = 0): array
    {
        // Bounded, because a log context can contain a cyclic-looking structure
        // and a stack overflow inside the logger takes down the request that
        // was trying to report a problem.
        if ($depth > 8) {
            return $values;
        }

        $redacted = [];

        foreach ($values as $key => $value) {
            if (is_string($key) && $this->isSensitive($key)) {
                $redacted[$key] = self::REDACTED;

                continue;
            }

            $redacted[$key] = is_array($value) ? $this->redact($value, $depth + 1) : $value;
        }

        return $redacted;
    }

    private function isSensitive(string $key): bool
    {
        $normalised = str_replace(['-', ' ', '.'], '_', mb_strtolower($key));

        foreach (self::SENSITIVE_KEYS as $needle) {
            if (str_contains($normalised, $needle)) {
                return true;
            }
        }

        return false;
    }
}
