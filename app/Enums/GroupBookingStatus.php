<?php

namespace App\Enums;

/**
 * Where a group trip has got to.
 *
 * `ManifestPending` is a state of its own rather than a flag, because a group
 * that is agreed but has no names on the list is genuinely different work from
 * one that is ready to travel — and it is the state most groups sit in longest.
 */
enum GroupBookingStatus: string
{
    case Enquiry = 'enquiry';
    case Quoted = 'quoted';
    case ManifestPending = 'manifest_pending';
    case Confirmed = 'confirmed';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Enquiry => 'Enquiry',
            self::Quoted => 'Quoted',
            self::ManifestPending => 'Awaiting the traveller list',
            self::Confirmed => 'Confirmed',
            self::InProgress => 'In progress',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
        };
    }

    /** Whether the traveller list may still be edited. */
    public function manifestIsEditable(): bool
    {
        return in_array($this, [
            self::Enquiry,
            self::Quoted,
            self::ManifestPending,
            self::Confirmed,
        ], true);
    }

    public function isOpen(): bool
    {
        return ! in_array($this, [self::Completed, self::Cancelled], true);
    }

    public function tone(): string
    {
        return match ($this) {
            self::Enquiry => 'slate',
            self::Quoted, self::ManifestPending => 'amber',
            self::Confirmed => 'sky',
            self::InProgress => 'emerald',
            self::Completed => 'slate',
            self::Cancelled => 'rose',
        };
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Enquiry => [self::Quoted, self::Cancelled],
            self::Quoted => [self::ManifestPending, self::Cancelled],
            self::ManifestPending => [self::Confirmed, self::Cancelled],
            self::Confirmed => [self::InProgress, self::Cancelled],
            self::InProgress => [self::Completed],
            self::Completed, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    /** Coarse bucket for the cross-domain booking list. */
    public function stage(): BookingStage
    {
        return match ($this) {
            self::Enquiry, self::Quoted, self::ManifestPending => BookingStage::AwaitingAction,
            self::Confirmed => BookingStage::Confirmed,
            self::InProgress => BookingStage::InProgress,
            self::Completed => BookingStage::Completed,
            self::Cancelled => BookingStage::Closed,
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

    /** @return list<string> */
    public static function openValues(): array
    {
        return array_map(
            static fn (self $case): string => $case->value,
            array_values(array_filter(self::cases(), static fn (self $case): bool => $case->isOpen())),
        );
    }
}
