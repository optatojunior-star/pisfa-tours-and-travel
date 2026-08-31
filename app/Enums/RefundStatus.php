<?php

namespace App\Enums;

enum RefundStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Processing => 'Processing',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
        };
    }

    /**
     * Only a completed refund reduces the parent payment's settled amount. A
     * pending one must not, or reporting would understate revenue that has not
     * actually left the account yet.
     */
    public function reducesSettledAmount(): bool
    {
        return $this === self::Completed;
    }

    /** Still holds a claim on the payment, so it counts against refundable headroom. */
    public function isInFlight(): bool
    {
        return in_array($this, [self::Pending, self::Processing], true);
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Processing, self::Completed, self::Failed],
            self::Processing => [self::Completed, self::Failed],
            self::Completed, self::Failed => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    /** @return list<string> */
    public static function claimingValues(): array
    {
        return array_map(
            static fn (self $status): string => $status->value,
            array_filter(
                self::cases(),
                static fn (self $status): bool => $status->isInFlight() || $status->reducesSettledAmount(),
            ),
        );
    }
}
