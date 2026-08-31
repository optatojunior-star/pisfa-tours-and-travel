<?php

namespace App\Enums;

/**
 * What somebody may do on their employer's account.
 *
 * A role on a membership row rather than a flag on the user, because the same
 * person may be an administrator at one company and merely a traveller at
 * another — and because revoking authority has to be possible without touching
 * the bookings they already made.
 */
enum CorporateMemberRole: string
{
    case Traveller = 'traveller';
    case Booker = 'booker';
    case Approver = 'approver';
    case Administrator = 'administrator';

    public function label(): string
    {
        return match ($this) {
            self::Traveller => 'Traveller',
            self::Booker => 'Booker',
            self::Approver => 'Approver',
            self::Administrator => 'Account administrator',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Traveller => 'Travels on company bookings but does not raise them.',
            self::Booker => 'Raises group bookings on the company account.',
            self::Approver => 'Raises bookings and signs off spending on the account.',
            self::Administrator => 'Everything, including managing who else is on the account.',
        };
    }

    /** Whether they may raise a booking against the company. */
    public function canBook(): bool
    {
        return in_array($this, [self::Booker, self::Approver, self::Administrator], true);
    }

    /** Whether they may commit the company to spending. */
    public function canApprove(): bool
    {
        return in_array($this, [self::Approver, self::Administrator], true);
    }

    /** Whether they may add and remove colleagues. */
    public function canManageMembers(): bool
    {
        return $this === self::Administrator;
    }
}
