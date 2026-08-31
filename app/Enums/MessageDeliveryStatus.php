<?php

namespace App\Enums;

/**
 * How far an outbound message has got.
 *
 * The ranking is the point of this enum. A provider's callbacks arrive out of
 * order — a `read` can land before the `delivered` that logically precedes it —
 * so delivery state is advanced by rank and never assigned blindly. Without
 * that, a late callback would drag a message that was demonstrably read back to
 * merely delivered, and the console would tell staff a customer had not seen a
 * message they had already replied to.
 */
enum MessageDeliveryStatus: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Read = 'read';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Queued',
            self::Sent => 'Sent',
            self::Delivered => 'Delivered',
            self::Read => 'Read',
            self::Failed => 'Failed',
        };
    }

    /**
     * How far along the delivery path this state is.
     *
     * `Failed` sits outside the ladder: it is a terminal outcome rather than a
     * further step, and is handled explicitly by `advanceTo()`.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Pending => 0,
            self::Sent => 1,
            self::Delivered => 2,
            self::Read => 3,
            self::Failed => -1,
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Read, self::Failed], true);
    }

    public function isFailure(): bool
    {
        return $this === self::Failed;
    }

    /**
     * The state after a callback reporting `$reported`.
     *
     * Returns the current state unchanged when the callback carries nothing
     * new, so a duplicate or late delivery receipt is a no-op rather than a
     * regression.
     */
    public function advanceTo(self $reported): self
    {
        // A failure report is only believed while the message has not already
        // been shown to have arrived: a provider that reports both has told us
        // the message got there, which is the fact that matters.
        if ($reported === self::Failed) {
            return $this->rank() >= self::Delivered->rank() ? $this : self::Failed;
        }

        // Nothing recovers a failed send except a fresh attempt, which creates
        // its own message rather than resurrecting this one.
        if ($this === self::Failed) {
            return self::Failed;
        }

        return $reported->rank() > $this->rank() ? $reported : $this;
    }

    public function tone(): string
    {
        return match ($this) {
            self::Pending => 'slate',
            self::Sent => 'sky',
            self::Delivered => 'indigo',
            self::Read => 'emerald',
            self::Failed => 'rose',
        };
    }
}
