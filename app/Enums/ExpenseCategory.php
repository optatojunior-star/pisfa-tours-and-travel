<?php

namespace App\Enums;

/**
 * What an expense was for.
 *
 * `attachesToVehicle()` is the one that matters operationally: it decides which
 * categories can be charged to a specific vehicle, which is what makes an
 * expense recoverable against a lease.
 */
enum ExpenseCategory: string
{
    case Fuel = 'fuel';
    case Maintenance = 'maintenance';
    case Tyres = 'tyres';
    case Insurance = 'insurance';
    case Licensing = 'licensing';
    case Tolls = 'tolls';
    case DriverAllowance = 'driver_allowance';
    case ParkAndEntryFees = 'park_and_entry_fees';
    case Accommodation = 'accommodation';
    case Office = 'office';
    case Marketing = 'marketing';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Fuel => 'Fuel',
            self::Maintenance => 'Maintenance and repairs',
            self::Tyres => 'Tyres',
            self::Insurance => 'Insurance',
            self::Licensing => 'Licensing and permits',
            self::Tolls => 'Tolls and parking',
            self::DriverAllowance => 'Driver allowance',
            self::ParkAndEntryFees => 'Park and entry fees',
            self::Accommodation => 'Accommodation',
            self::Office => 'Office and admin',
            self::Marketing => 'Marketing',
            self::Other => 'Other',
        };
    }

    /**
     * Whether this kind of spending belongs to a particular vehicle.
     *
     * Office rent does not; a set of tyres does. Only vehicle-attached spending
     * can be recovered from a lease owner, which is why the distinction is here
     * rather than left to whoever fills the form in.
     */
    public function attachesToVehicle(): bool
    {
        return in_array($this, [
            self::Fuel,
            self::Maintenance,
            self::Tyres,
            self::Insurance,
            self::Licensing,
            self::Tolls,
        ], true);
    }

    /**
     * Whether it is normally recoverable from a lease owner.
     *
     * Fuel is not: PISFA earns the hire income, so it buys the fuel. Wear and
     * paperwork on somebody else's asset is a different matter, and the lease
     * terms decide it — this only marks what may be *proposed* as a deduction.
     */
    public function isRecoverableFromOwner(): bool
    {
        return in_array($this, [self::Maintenance, self::Tyres, self::Insurance, self::Licensing], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
