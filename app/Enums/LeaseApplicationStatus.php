<?php

namespace App\Enums;

/**
 * Where an owner's offer of their vehicle has got to.
 *
 * `Inspected` is a state rather than a flag because PISFA takes on liability for
 * a car it puts on hire: a lease agreed without somebody having looked at the
 * vehicle is a promise made about something nobody has seen.
 */
enum LeaseApplicationStatus: string
{
    case Submitted = 'submitted';
    case UnderReview = 'under_review';
    case InspectionArranged = 'inspection_arranged';
    case Inspected = 'inspected';
    case Approved = 'approved';
    case Declined = 'declined';
    case Withdrawn = 'withdrawn';

    public function label(): string
    {
        return match ($this) {
            self::Submitted => 'Submitted',
            self::UnderReview => 'Under review',
            self::InspectionArranged => 'Inspection arranged',
            self::Inspected => 'Inspected',
            self::Approved => 'Approved',
            self::Declined => 'Declined',
            self::Withdrawn => 'Withdrawn by owner',
        };
    }

    /** Still work in somebody's queue. */
    public function isOpen(): bool
    {
        return in_array($this, [
            self::Submitted,
            self::UnderReview,
            self::InspectionArranged,
            self::Inspected,
        ], true);
    }

    public function tone(): string
    {
        return match ($this) {
            self::Submitted => 'amber',
            self::UnderReview, self::InspectionArranged, self::Inspected => 'sky',
            self::Approved => 'emerald',
            self::Declined, self::Withdrawn => 'rose',
        };
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Submitted => [self::UnderReview, self::Declined, self::Withdrawn],
            self::UnderReview => [self::InspectionArranged, self::Declined, self::Withdrawn],
            self::InspectionArranged => [self::Inspected, self::Declined, self::Withdrawn],
            // Approval is only reachable once somebody has looked at the car.
            self::Inspected => [self::Approved, self::Declined, self::Withdrawn],
            self::Approved, self::Declined, self::Withdrawn => [],
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
