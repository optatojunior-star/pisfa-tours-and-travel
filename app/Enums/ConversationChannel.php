<?php

namespace App\Enums;

/**
 * How a conversation reaches the person on the other end.
 *
 * The channel is recorded per message as well as per conversation, because a
 * thread that starts on the website and continues on WhatsApp is one
 * conversation — and a reply has to go back the way the last message came in.
 */
enum ConversationChannel: string
{
    case Web = 'web';
    case WhatsApp = 'whatsapp';

    public function label(): string
    {
        return match ($this) {
            self::Web => 'Website chat',
            self::WhatsApp => 'WhatsApp',
        };
    }

    /**
     * Whether reaching this channel needs a phone number.
     *
     * Website chat is delivered by the page polling for new messages; WhatsApp
     * needs an address the provider can send to, so a conversation on it is
     * unusable without one.
     */
    public function requiresPhoneNumber(): bool
    {
        return $this === self::WhatsApp;
    }

    /** Whether outbound messages leave through an external provider. */
    public function usesProvider(): bool
    {
        return $this === self::WhatsApp;
    }

    public function tone(): string
    {
        return match ($this) {
            self::Web => 'sky',
            self::WhatsApp => 'emerald',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
