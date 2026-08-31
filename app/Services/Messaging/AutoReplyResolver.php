<?php

namespace App\Services\Messaging;

use App\Models\Conversation;
use App\Models\ConversationMessage;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Decides whether an inbound message gets an automatic reply, and what it says.
 *
 * Deterministic keyword matching, not a language model. An auto-reply on
 * WhatsApp costs money per message and is read as PISFA speaking, so what it
 * can say is a fixed, reviewable list in config rather than generated text.
 *
 * Three separate guards stop it running away, and each exists because of a
 * different way that automatic messaging goes wrong:
 *
 *  1. Only a message genuinely from the customer can trigger a reply, so the
 *     bot can never answer itself — two such systems pointed at each other
 *     would otherwise talk until somebody read the bill.
 *  2. A cooldown per conversation, so somebody typing five short messages in a
 *     row gets one answer rather than five.
 *  3. Nothing at all once a member of staff has joined the thread: a canned
 *     answer arriving after a real person has taken over is worse than silence.
 */
class AutoReplyResolver
{
    /**
     * @return array{body: string, rule: string}|null
     */
    public function for(Conversation $conversation, ConversationMessage $message, ?DateTimeInterface $at = null): ?array
    {
        if (! config('messaging.bot.enabled', true)) {
            return null;
        }

        // Guard 1: only ever answers the customer.
        if (! $message->author_type->canTriggerAutoReply() || $message->is_internal_note) {
            return null;
        }

        // Guard 3: a human has this one.
        if ($this->staffHaveJoined($conversation)) {
            return null;
        }

        $now = CarbonImmutable::instance($at === null ? now() : CarbonImmutable::instance($at));

        // Guard 2: one automatic answer per cooldown window.
        $cooldown = (int) config('messaging.bot.cooldown_minutes', 30);

        if ($conversation->last_auto_reply_at !== null
            && $conversation->last_auto_reply_at->addMinutes($cooldown)->isAfter($now)) {
            return null;
        }

        $matched = $this->matchKeyword($message->body);

        if ($matched !== null) {
            return $matched;
        }

        // Nothing matched. Outside working hours the caller still deserves to
        // know when somebody will read this; inside them, silence is correct —
        // a real reply is coming.
        return $this->isWithinBusinessHours($now) ? null : $this->awayMessage();
    }

    /**
     * A member of staff writing in the thread ends automatic replies for good.
     *
     * Deliberately not limited to the cooldown window: once a person has taken
     * a conversation on, a canned message arriving later reads as PISFA not
     * paying attention.
     */
    private function staffHaveJoined(Conversation $conversation): bool
    {
        return $conversation->last_staff_message_at !== null;
    }

    /**
     * @return array{body: string, rule: string}|null
     */
    private function matchKeyword(string $body): ?array
    {
        $haystack = mb_strtolower($body);

        /** @var array<int, array{keywords: list<string>, reply: string, name?: string}> $rules */
        $rules = config('messaging.bot.rules', []);

        foreach ($rules as $index => $rule) {
            foreach ($rule['keywords'] ?? [] as $keyword) {
                $keyword = mb_strtolower(trim((string) $keyword));

                if ($keyword !== '' && str_contains($haystack, $keyword)) {
                    return [
                        'body' => (string) $rule['reply'],
                        'rule' => (string) ($rule['name'] ?? 'rule-'.$index),
                    ];
                }
            }
        }

        return null;
    }

    /**
     * Business hours in PISFA's own timezone.
     *
     * Read in Africa/Kampala rather than UTC, because "are we open" is a
     * question about the Kampala office clock and nothing else.
     */
    public function isWithinBusinessHours(?DateTimeInterface $at = null): bool
    {
        $local = CarbonImmutable::instance($at === null ? now() : CarbonImmutable::instance($at))
            ->setTimezone((string) config('pisfa.timezone', 'Africa/Kampala'));

        /** @var array<int, array{0: string, 1: string}|null> $week */
        $week = config('messaging.bot.business_hours', []);

        // Carbon's dayOfWeek is 0 for Sunday; the config is indexed the same
        // way so a reader can line the two up without counting.
        $today = $week[$local->dayOfWeek] ?? null;

        if (! is_array($today) || count($today) !== 2) {
            return false;
        }

        $minutes = ($local->hour * 60) + $local->minute;

        return $minutes >= $this->minutesOfDay($today[0]) && $minutes < $this->minutesOfDay($today[1]);
    }

    /**
     * @return array{body: string, rule: string}
     */
    private function awayMessage(): array
    {
        return [
            'body' => (string) config(
                'messaging.bot.away_message',
                'Thank you for contacting PISFA Tours and Travel. Our office is closed at the moment. '
                .'A member of the team will reply when we open.',
            ),
            'rule' => 'away',
        ];
    }

    private function minutesOfDay(string $time): int
    {
        [$hour, $minute] = array_pad(explode(':', $time, 2), 2, '0');

        return ((int) $hour * 60) + (int) $minute;
    }
}
