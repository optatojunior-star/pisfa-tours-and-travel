<?php

namespace App\Enums;

enum TourBookingEventType: string
{
    case DepartureReminderSent = 'departure_reminder_sent';
    case LoyaltyEligible = 'loyalty_eligible';
    case ReviewRequestSent = 'review_request_sent';

    public function label(): string
    {
        return match ($this) {
            self::DepartureReminderSent => 'Departure reminder sent',
            self::LoyaltyEligible => 'Eligible for loyalty processing',
            self::ReviewRequestSent => 'Review request sent',
        };
    }
}
