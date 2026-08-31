<?php

namespace App\Enums;

/**
 * Where a stay is in its life.
 *
 * `holdsInventory()` is the one that matters: it decides which bookings count
 * against a room type's quantity when availability is recomputed. A cancelled
 * or expired stay must stop consuming a room the moment it closes, or the
 * property slowly sells out on paper while rooms stand empty.
 */
enum PropertyBookingStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case CheckedIn = 'checked_in';
    case CheckedOut = 'checked_out';
    case Cancelled = 'cancelled';
    case Declined = 'declined';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending review',
            self::Confirmed => 'Confirmed',
            self::CheckedIn => 'Checked in',
            self::CheckedOut => 'Checked out',
            self::Cancelled => 'Cancelled',
            self::Declined => 'Declined',
            self::Expired => 'Expired',
        };
    }

    /**
     * Whether this booking still occupies a room.
     *
     * A checked-out stay does not: the room is free again from the checkout
     * date, and the half-open night interval already excludes that night.
     */
    public function holdsInventory(): bool
    {
        return in_array($this, [self::Pending, self::Confirmed, self::CheckedIn], true);
    }

    public function tone(): string
    {
        return match ($this) {
            self::Pending => 'amber',
            self::Confirmed => 'sky',
            self::CheckedIn => 'emerald',
            self::CheckedOut => 'slate',
            self::Cancelled, self::Declined, self::Expired => 'rose',
        };
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Confirmed, self::Cancelled, self::Declined, self::Expired],
            self::Confirmed => [self::CheckedIn, self::Cancelled],
            self::CheckedIn => [self::CheckedOut],
            self::CheckedOut, self::Cancelled, self::Declined, self::Expired => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    /** @return list<string> */
    public static function inventoryHoldingValues(): array
    {
        return array_map(
            static fn (self $status): string => $status->value,
            array_values(array_filter(self::cases(), static fn (self $status): bool => $status->holdsInventory())),
        );
    }

    /** Coarse bucket for the cross-domain booking list. */
    public function stage(): BookingStage
    {
        return match ($this) {
            self::Pending => BookingStage::AwaitingAction,
            self::Confirmed => BookingStage::Confirmed,
            self::CheckedIn => BookingStage::InProgress,
            self::CheckedOut => BookingStage::Completed,
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
