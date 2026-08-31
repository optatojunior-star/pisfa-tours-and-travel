<?php

namespace App\Support\Export;

use App\Enums\UserRole;
use App\Models\User;

/**
 * What may be exported, and who may export it.
 *
 * An allowlist keyed by URL segment, because the alternative — a table or model
 * name from the query string — would let a caller export any row in the
 * database, including tokens and password hashes.
 *
 * Financial datasets sit above operational ones: a booking list is what staff
 * work from every day, while a payments extract is the accounting record.
 */
enum ExportDataset: string
{
    case Bookings = 'bookings';
    case Payments = 'payments';
    case Invoices = 'invoices';
    case Customers = 'customers';
    case Fleet = 'fleet';

    public function label(): string
    {
        return match ($this) {
            self::Bookings => 'Bookings',
            self::Payments => 'Payments',
            self::Invoices => 'Invoices',
            self::Customers => 'Customers',
            self::Fleet => 'Fleet costs',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Bookings => 'Every booking across tours, car hire, transfers, and imports, with its status and value.',
            self::Payments => 'Settled and failed payments, with the base-currency amount stamped at settlement.',
            self::Invoices => 'Invoices with their totals, balances, and due dates.',
            self::Customers => 'Customer accounts with booking counts and lifetime value. No credentials.',
            self::Fleet => 'Maintenance and fuel costs per vehicle.',
        };
    }

    /** Whether the dataset is accounting rather than day-to-day operations. */
    public function isFinancial(): bool
    {
        return in_array($this, [self::Payments, self::Invoices, self::Customers], true);
    }

    public function isAvailableTo(?User $user): bool
    {
        if ($user === null || ! $user->isActive()) {
            return false;
        }

        if ($this->isFinancial()) {
            return $user->hasAnyRole(UserRole::Manager, UserRole::SuperAdmin);
        }

        return $user->canAccessAdministration();
    }

    /** @return list<self> */
    public static function availableTo(?User $user): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $case): bool => $case->isAvailableTo($user),
        ));
    }
}
