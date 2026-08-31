<?php

namespace App\Enums;

enum InspectionPhase: string
{
    case PreTrip = 'pre_trip';
    case PostTrip = 'post_trip';

    public function label(): string
    {
        return match ($this) {
            self::PreTrip => 'Pre-trip check',
            self::PostTrip => 'Post-trip check',
        };
    }

    /**
     * A pre-trip check is what authorises the trip to start, so a defect found
     * then stops the vehicle leaving. A post-trip defect is reported for repair
     * but the journey is already over.
     */
    public function blocksDeparture(): bool
    {
        return $this === self::PreTrip;
    }
}
