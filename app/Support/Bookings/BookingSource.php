<?php

namespace App\Support\Bookings;

use App\Enums\AirportTransferBookingStatus;
use App\Enums\BookingStage;
use App\Enums\CarHireBookingStatus;
use App\Enums\GroupBookingStatus;
use App\Enums\PropertyBookingStatus;
use App\Enums\TourBookingStatus;
use App\Enums\VehicleImportStatus;
use App\Models\AirportTransferBooking;
use App\Models\CarHireBooking;
use App\Models\GroupBooking;
use App\Models\PropertyBooking;
use App\Models\TourBooking;
use App\Models\VehicleImportOrder;
use Illuminate\Database\Eloquent\Model;

/**
 * The four domains the unified booking list draws from, and how each one
 * projects onto the shared column set.
 *
 * An allowlist keyed by URL segment rather than a class name from the request:
 * accepting a table name from a query string would let a caller point the
 * console at anything.
 */
enum BookingSource: string
{
    case Tours = 'tours';
    case CarHire = 'car-hire';
    case AirportTransfers = 'airport-transfers';
    case VehicleImports = 'vehicle-imports';
    case Accommodation = 'accommodation';
    case Groups = 'groups';

    public function label(): string
    {
        return match ($this) {
            self::Tours => 'Tour',
            self::CarHire => 'Car hire',
            self::AirportTransfers => 'Airport transfer',
            self::VehicleImports => 'Vehicle import',
            self::Accommodation => 'Accommodation',
            self::Groups => 'Group booking',
        };
    }

    public function table(): string
    {
        return match ($this) {
            self::Tours => 'tour_bookings',
            self::CarHire => 'car_hire_bookings',
            self::AirportTransfers => 'airport_transfer_bookings',
            self::VehicleImports => 'vehicle_import_orders',
            self::Accommodation => 'property_bookings',
            self::Groups => 'group_bookings',
        };
    }

    /** @return class-string<Model> */
    public function model(): string
    {
        return match ($this) {
            self::Tours => TourBooking::class,
            self::CarHire => CarHireBooking::class,
            self::AirportTransfers => AirportTransferBooking::class,
            self::VehicleImports => VehicleImportOrder::class,
            self::Accommodation => PropertyBooking::class,
            self::Groups => GroupBooking::class,
        };
    }

    /**
     * Column holding the money owed, in minor units.
     *
     * An import has no price until it is quoted, which is why its column is
     * nullable and the projection coalesces it to zero rather than dropping the
     * row: an unpriced enquiry is still work in the queue.
     */
    public function amountColumn(): string
    {
        return match ($this) {
            self::Tours, self::CarHire, self::Accommodation => 'total_minor',
            self::Groups => 'quoted_total_minor',
            self::AirportTransfers => 'amount_minor',
            self::VehicleImports => 'total_price_minor',
        };
    }

    public function currencyColumn(): string
    {
        return match ($this) {
            self::VehicleImports => 'budget_currency',
            default => 'currency',
        };
    }

    /**
     * When the service actually happens.
     *
     * An import has no service date — it runs for months — so it falls back to
     * its own creation time rather than inventing one.
     */
    public function serviceDateColumn(): string
    {
        return match ($this) {
            self::Tours => 'departure_starts_at_snapshot',
            self::CarHire => 'pickup_at',
            self::AirportTransfers => 'service_starts_at',
            self::VehicleImports => 'created_at',
            self::Accommodation => 'check_in_date',
            self::Groups => 'starts_on',
        };
    }

    /**
     * Whether the service date is a calendar date rather than a moment.
     *
     * A stay's arrival is the 12th, full stop — it has no timezone, because
     * "the 12th" means the same thing to the guest and the desk. Filtering it
     * with a UTC instant converted from a Kampala day would compare a date
     * against a time, which happens to work at a three-hour offset and would
     * stop working the moment somebody changed the business timezone.
     */
    public function serviceDateIsCalendarDate(): bool
    {
        return in_array($this, [self::Accommodation, self::Groups], true);
    }

    /**
     * The column naming the person the booking belongs to.
     *
     * A group is raised by an organiser rather than by a customer, and the
     * column is named accordingly. Mapping it here keeps the projection honest
     * instead of adding a duplicate `customer_id` to the groups table purely to
     * satisfy one query.
     */
    public function customerColumn(): string
    {
        return match ($this) {
            self::Groups => 'organiser_id',
            default => 'customer_id',
        };
    }

    /**
     * The contact columns, which a group does not carry.
     *
     * A group's contact *is* its organiser, so there is nothing to snapshot; the
     * projection coalesces to an empty string rather than joining the users
     * table inside a UNION arm.
     */
    public function contactNameColumn(): string
    {
        return match ($this) {
            self::Groups => "''",
            default => 'contact_name',
        };
    }

    public function contactEmailColumn(): string
    {
        return match ($this) {
            self::Groups => "''",
            default => 'contact_email',
        };
    }

    /** A short human description, built from snapshot columns. */
    public function summaryColumn(): string
    {
        return match ($this) {
            self::Tours => 'package_name_snapshot',
            self::CarHire => 'vehicle_name_snapshot',
            self::AirportTransfers => 'location_name_snapshot',
            self::VehicleImports => 'model',
            self::Accommodation => 'property_name_snapshot',
            self::Groups => 'title',
        };
    }

    /** @return list<string> Status values belonging to the given stage. */
    public function statusValuesInStage(BookingStage $stage): array
    {
        return match ($this) {
            self::Tours => TourBookingStatus::valuesInStage($stage),
            self::CarHire => CarHireBookingStatus::valuesInStage($stage),
            self::AirportTransfers => AirportTransferBookingStatus::valuesInStage($stage),
            self::VehicleImports => VehicleImportStatus::valuesInStage($stage),
            self::Accommodation => PropertyBookingStatus::valuesInStage($stage),
            self::Groups => GroupBookingStatus::valuesInStage($stage),
        };
    }

    public function stageFor(string $status): ?BookingStage
    {
        return match ($this) {
            self::Tours => TourBookingStatus::tryFrom($status)?->stage(),
            self::CarHire => CarHireBookingStatus::tryFrom($status)?->stage(),
            self::AirportTransfers => AirportTransferBookingStatus::tryFrom($status)?->stage(),
            self::VehicleImports => VehicleImportStatus::tryFrom($status)?->stage(),
            self::Accommodation => PropertyBookingStatus::tryFrom($status)?->stage(),
            self::Groups => GroupBookingStatus::tryFrom($status)?->stage(),
        };
    }

    public function statusLabel(string $status): string
    {
        return match ($this) {
            self::Tours => TourBookingStatus::tryFrom($status)?->label() ?? $status,
            self::CarHire => CarHireBookingStatus::tryFrom($status)?->label() ?? $status,
            self::AirportTransfers => AirportTransferBookingStatus::tryFrom($status)?->label() ?? $status,
            self::VehicleImports => VehicleImportStatus::tryFrom($status)?->label() ?? $status,
            self::Accommodation => PropertyBookingStatus::tryFrom($status)?->label() ?? $status,
            self::Groups => GroupBookingStatus::tryFrom($status)?->label() ?? $status,
        };
    }

    /** Where the console opens this record. */
    public function consoleRoute(string $reference): string
    {
        return match ($this) {
            self::Tours => route('admin.tour-bookings.show', $reference),
            self::CarHire => route('admin.car-hire-bookings.show', $reference),
            self::AirportTransfers => route('admin.airport-transfer-bookings.show', $reference),
            self::VehicleImports => route('admin.vehicle-imports.show', $reference),
            self::Accommodation => route('admin.accommodation.bookings.show', $reference),
            self::Groups => route('admin.corporate.groups.show', $reference),
        };
    }
}
