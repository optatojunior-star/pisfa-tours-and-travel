<?php

namespace App\Support\Portal;

use App\Support\Bookings\BookingSource;

/**
 * The kinds of thing that appear in a customer's activity list.
 *
 * An allowlist, so a filter value from the query string selects a projection
 * this class defines rather than naming a table.
 */
enum ActivityKind: string
{
    case Bookings = 'bookings';
    case Quotations = 'quotations';
    case Invoices = 'invoices';
    case Payments = 'payments';

    public function label(): string
    {
        return match ($this) {
            self::Bookings => 'Bookings',
            self::Quotations => 'Quotations',
            self::Invoices => 'Invoices',
            self::Payments => 'Payments',
        };
    }

    /**
     * The customer-facing route for one row.
     *
     * Returns null when the row has no page of its own — a payment is shown
     * through the thing it paid for, and offering a dead link would be worse
     * than offering none.
     */
    public function urlFor(string $source, string $reference): ?string
    {
        return match ($this) {
            self::Bookings => $this->bookingUrl($source, $reference),
            self::Quotations => route('portal.quotations.show', $reference),
            self::Invoices => route('portal.invoices.show', $reference),
            self::Payments => null,
        };
    }

    private function bookingUrl(string $source, string $reference): ?string
    {
        return match (BookingSource::tryFrom($source)) {
            BookingSource::Tours => route('portal.bookings.show', $reference),
            BookingSource::CarHire => route('portal.car-hire-bookings.show', $reference),
            BookingSource::AirportTransfers => route('portal.airport-transfer-bookings.show', $reference),
            BookingSource::VehicleImports => route('portal.vehicle-imports.show', $reference),
            BookingSource::Accommodation => route('portal.property-bookings.show', $reference),
            default => null,
        };
    }

    /** A human label for the source column, whichever kind it belongs to. */
    public function sourceLabel(string $source): string
    {
        if ($this === self::Bookings) {
            return BookingSource::tryFrom($source)?->label() ?? $this->label();
        }

        return $this->label();
    }
}
