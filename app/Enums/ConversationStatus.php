<?php

namespace App\Enums;

/**
 * Whose court the conversation is in.
 *
 * `AwaitingCustomer` is not a nicety: a queue that mixes "nobody has replied to
 * this person" with "we replied and are waiting on them" tells staff nothing
 * about what needs doing, and the first of those is the only one that is
 * actually overdue.
 */
enum ConversationStatus: string
{
    case Open = 'open';
    case AwaitingCustomer = 'awaiting_customer';
    case Resolved = 'resolved';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Needs a reply',
            self::AwaitingCustomer => 'Waiting on the customer',
            self::Resolved => 'Resolved',
            self::Closed => 'Closed',
        };
    }

    /** Whether it still belongs in somebody's queue. */
    public function isOpen(): bool
    {
        return in_array($this, [self::Open, self::AwaitingCustomer], true);
    }

    /**
     * Whether a new message may be added.
     *
     * A resolved thread reopens when the customer writes again — see
     * PostMessage — but a closed one does not: closing is the deliberate end of
     * a conversation, and a reply to it starts a new thread.
     */
    public function acceptsMessages(): bool
    {
        return $this !== self::Closed;
    }

    public function tone(): string
    {
        return match ($this) {
            self::Open => 'amber',
            self::AwaitingCustomer => 'sky',
            self::Resolved => 'emerald',
            self::Closed => 'slate',
        };
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Open => [self::AwaitingCustomer, self::Resolved, self::Closed],
            self::AwaitingCustomer => [self::Open, self::Resolved, self::Closed],
            self::Resolved => [self::Open, self::Closed],
            self::Closed => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    /** @return list<string> */
    public static function openValues(): array
    {
        return array_map(
            static fn (self $case): string => $case->value,
            array_values(array_filter(self::cases(), static fn (self $case): bool => $case->isOpen())),
        );
    }
}
