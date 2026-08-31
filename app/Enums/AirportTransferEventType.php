<?php

namespace App\Enums;

enum AirportTransferEventType: string
{
    case PickupReminder = 'pickup_reminder';
    case BookingExpired = 'booking_expired';
    case LoyaltyEligible = 'loyalty_eligible';
}
