<?php

namespace App\Enums;

enum PropertyBookingEventType: string
{
    case ArrivalReminderSent = 'arrival_reminder_sent';
    case BookingExpired = 'booking_expired';
    case LoyaltyEligible = 'loyalty_eligible';
}
