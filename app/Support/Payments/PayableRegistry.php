<?php

namespace App\Support\Payments;

use App\Contracts\Payments\Payable;
use App\Models\AirportTransferBooking;
use App\Models\CarHireBooking;
use App\Models\Invoice;
use App\Models\PropertyBooking;
use App\Models\TourBooking;
use App\Models\VehicleImportOrder;
use Illuminate\Database\Eloquent\Model;

/**
 * Maps URL segments to payable models, in both directions.
 *
 * An allowlist rather than a resolved class name: accepting a model class from
 * the URL would let a caller point checkout at any table. Keeping the reverse
 * lookup here too stops a view hardcoding the wrong segment in a retry link.
 */
final class PayableRegistry
{
    /** @var array<string, class-string<Model&Payable>> */
    private const TYPES = [
        'tour-bookings' => TourBooking::class,
        'car-hire-bookings' => CarHireBooking::class,
        'airport-transfers' => AirportTransferBooking::class,
        'vehicle-imports' => VehicleImportOrder::class,
        'stays' => PropertyBooking::class,
        'invoices' => Invoice::class,
    ];

    /** @return class-string<Model&Payable>|null */
    public static function classFor(string $segment): ?string
    {
        return self::TYPES[$segment] ?? null;
    }

    /** The URL segment for a payable, or null if it is not checkout-reachable. */
    public static function segmentFor(Payable $payable): ?string
    {
        if (! $payable instanceof Model) {
            return null;
        }

        foreach (self::TYPES as $segment => $class) {
            if ($payable instanceof $class) {
                return $segment;
            }
        }

        return null;
    }

    /**
     * Checkout URL for a payable, or null when it cannot be paid online.
     * Returning null lets a view omit the control rather than render a
     * link that 404s.
     */
    public static function checkoutUrl(Payable $payable): ?string
    {
        $segment = self::segmentFor($payable);

        if ($segment === null) {
            return null;
        }

        return route('payments.checkout', [$segment, $payable->paymentReference()]);
    }
}
