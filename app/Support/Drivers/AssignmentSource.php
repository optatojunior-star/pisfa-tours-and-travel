<?php

namespace App\Support\Drivers;

use App\Models\AirportTransferAssignment;
use App\Models\CarHireDriverAssignment;
use App\Models\TourAssignment;
use Illuminate\Database\Eloquent\Model;

/**
 * The three places a driver's work comes from.
 *
 * The assignment tables are structurally identical, which is what lets one
 * driver screen union them. An allowlist keyed by segment rather than a class
 * name from the request: accepting a table name would let a caller point the
 * portal at anything.
 */
enum AssignmentSource: string
{
    case Tours = 'tours';
    case CarHire = 'car-hire';
    case AirportTransfers = 'airport-transfers';

    public function label(): string
    {
        return match ($this) {
            self::Tours => 'Tour',
            self::CarHire => 'Car hire',
            self::AirportTransfers => 'Airport transfer',
        };
    }

    public function table(): string
    {
        return match ($this) {
            self::Tours => 'tour_assignments',
            self::CarHire => 'car_hire_driver_assignments',
            self::AirportTransfers => 'airport_transfer_assignments',
        };
    }

    /** @return class-string<Model> */
    public function model(): string
    {
        return match ($this) {
            self::Tours => TourAssignment::class,
            self::CarHire => CarHireDriverAssignment::class,
            self::AirportTransfers => AirportTransferAssignment::class,
        };
    }

    public function bookingForeignKey(): string
    {
        return match ($this) {
            self::Tours => 'tour_booking_id',
            self::CarHire => 'car_hire_booking_id',
            self::AirportTransfers => 'airport_transfer_booking_id',
        };
    }

    public function bookingTable(): string
    {
        return match ($this) {
            self::Tours => 'tour_bookings',
            self::CarHire => 'car_hire_bookings',
            self::AirportTransfers => 'airport_transfer_bookings',
        };
    }

    /** The booking column holding a short description of the work. */
    public function bookingSummaryColumn(): string
    {
        return match ($this) {
            self::Tours => 'package_name_snapshot',
            self::CarHire => 'vehicle_name_snapshot',
            self::AirportTransfers => 'location_name_snapshot',
        };
    }

    /**
     * Where the vehicle comes from.
     *
     * Car hire and transfers name a vehicle; a tour may be run in something
     * outside the hire fleet, which is why its trip vehicle is nullable.
     */
    public function vehicleColumn(): ?string
    {
        return match ($this) {
            self::Tours => null,
            self::CarHire, self::AirportTransfers => 'vehicle_id',
        };
    }

    /** Whether the vehicle column lives on the assignment or on the booking. */
    public function vehicleOnAssignment(): bool
    {
        return $this === self::AirportTransfers;
    }

    public static function forModel(Model $assignment): ?self
    {
        foreach (self::cases() as $case) {
            if ($assignment instanceof ($case->model())) {
                return $case;
            }
        }

        return null;
    }
}
