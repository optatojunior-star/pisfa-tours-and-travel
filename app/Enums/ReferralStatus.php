<?php

namespace App\Enums;

enum ReferralStatus: string
{
    /** Signed up with a code; the welcome bonus is paid immediately. */
    case Pending = 'pending';

    /** The referred customer completed a first eligible booking. */
    case Qualified = 'qualified';

    /** The referrer reward has been paid. Terminal. */
    case Rewarded = 'rewarded';

    /** Disqualified, for example self-referral discovered after the fact. */
    case Void = 'void';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Awaiting first booking',
            self::Qualified => 'Qualified',
            self::Rewarded => 'Reward paid',
            self::Void => 'Void',
        };
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::Pending, self::Qualified], true);
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Qualified, self::Void],
            self::Qualified => [self::Rewarded, self::Void],
            self::Rewarded, self::Void => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }
}
