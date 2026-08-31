<?php

namespace App\Enums;

/**
 * Who wrote a message.
 *
 * Kept as an explicit column rather than inferred from whether `author_user_id`
 * is null, because three different things have no user attached — a guest
 * writing in, an automatic reply, and an internal note left by a system job —
 * and the auto-reply loop guard has to be able to tell them apart. Inferring it
 * would make a bot capable of answering itself.
 */
enum MessageAuthorType: string
{
    case Customer = 'customer';
    case Staff = 'staff';
    case Bot = 'bot';
    case System = 'system';

    public function label(): string
    {
        return match ($this) {
            self::Customer => 'Customer',
            self::Staff => 'PISFA',
            self::Bot => 'Automatic reply',
            self::System => 'System',
        };
    }

    /** Whether the message came from the person PISFA is talking to. */
    public function isInbound(): bool
    {
        return $this === self::Customer;
    }

    /**
     * Whether an automatic reply may be triggered by this message.
     *
     * Only a real inbound message qualifies. Allowing anything else would let
     * the bot answer its own greeting, and two systems configured this way
     * would talk to each other until somebody noticed the bill.
     */
    public function canTriggerAutoReply(): bool
    {
        return $this === self::Customer;
    }

    /** Whether the customer sees it, or it is staff-only. */
    public function isVisibleToCustomer(): bool
    {
        return $this !== self::System;
    }
}
