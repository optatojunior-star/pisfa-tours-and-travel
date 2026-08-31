<?php

namespace App\Enums;

enum AirportTransferBookingStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Declined = 'declined';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending review',
            self::Confirmed => 'Confirmed',
            self::InProgress => 'In progress',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
            self::Declined => 'Declined',
            self::Expired => 'Expired',
        };
    }

    public function holdsResources(): bool
    {
        return in_array($this, [self::Pending, self::Confirmed, self::InProgress], true);
    }

    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Confirmed, self::Cancelled, self::Declined, self::Expired],
            self::Confirmed => [self::InProgress, self::Cancelled],
            self::InProgress => [self::Completed],
            self::Completed, self::Cancelled, self::Declined, self::Expired => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    /** @return list<string> */
    public static function resourceHoldingValues(): array
    {
        return array_map(
            static fn (self $status): string => $status->value,
            array_filter(self::cases(), static fn (self $status): bool => $status->holdsResources()),
        );
    }

    /** Coarse bucket for the cross-domain booking list. */
    public function stage(): BookingStage
    {
        return match ($this) {
            self::Pending => BookingStage::AwaitingAction,
            self::Confirmed => BookingStage::Confirmed,
            self::InProgress => BookingStage::InProgress,
            self::Completed => BookingStage::Completed,
            self::Cancelled, self::Declined, self::Expired => BookingStage::Closed,
        };
    }

    /** @return list<string> */
    public static function valuesInStage(BookingStage $stage): array
    {
        return array_map(
            static fn (self $case): string => $case->value,
            array_values(array_filter(
                self::cases(),
                static fn (self $case): bool => $case->stage() === $stage,
            )),
        );
    }
}
