<?php

namespace App\Enums;

enum CarHireBookingEventType: string
{
    case ReturnReminderSent = 'return_reminder_sent';
    case BookingExpired = 'booking_expired';
    case LoyaltyEligible = 'loyalty_eligible';
}
