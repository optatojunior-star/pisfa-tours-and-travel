<?php

namespace App\Support;

/**
 * Links that open WhatsApp with a message already written.
 *
 * WhatsApp is how most customers in this market actually make contact. A web
 * form asks somebody to type their name, their email and their question into a
 * page they do not trust, and then to wait without knowing whether anything
 * arrived. A WhatsApp thread is a conversation they can see, keep, and come
 * back to — so where the site can hand them one already addressed and already
 * describing what they are looking at, it should.
 *
 * The number normalisation lives here rather than in a Blade file because two
 * components now need it and wa.me is unforgiving: digits only, country code
 * included, no plus, no spaces.
 */
final class WhatsApp
{
    /**
     * The configured business number as wa.me wants it, or null when no number
     * is configured — in which case every caller must render nothing rather
     * than a link to https://wa.me/?text=…, which opens a contact picker and
     * looks broken.
     */
    public static function number(): ?string
    {
        $digits = preg_replace('/[^0-9]/', '', (string) config('pisfa.company.whatsapp', ''));

        return $digits === '' || $digits === null ? null : $digits;
    }

    public static function isConfigured(): bool
    {
        return self::number() !== null;
    }

    /**
     * A click-to-chat link carrying `$message`.
     *
     * @param  list<string|null>  $lines  joined with newlines; nulls and blanks
     *                                    are dropped, so a caller can pass an
     *                                    optional detail without composing the
     *                                    string conditionally
     */
    public static function link(array $lines): ?string
    {
        $number = self::number();

        if ($number === null) {
            return null;
        }

        $text = implode("\n", array_filter(
            array_map(static fn (?string $line): string => trim((string) $line), $lines),
            static fn (string $line): bool => $line !== '',
        ));

        return 'https://wa.me/'.$number.'?text='.rawurlencode($text);
    }
}
