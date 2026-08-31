<?php

namespace App\Actions\Fleet;

use App\Models\Vehicle;
use Illuminate\Validation\ValidationException;

/**
 * The single guard on odometer progression.
 *
 * Maintenance records and fuel logs both carry a reading, and both go through
 * here. Without one place enforcing it, a fuel log entered out of order would
 * quietly move the fleet's mileage backwards and every consumption and
 * service-due figure derived from it would be wrong.
 *
 * The caller is responsible for holding a lock on the vehicle row: this runs
 * inside their transaction so the check and the advance cannot be interleaved.
 */
final class RecordOdometerReading
{
    /**
     * A generous ceiling on a single jump, so a typo of an extra digit is
     * rejected rather than permanently corrupting the vehicle's mileage.
     */
    public const MAXIMUM_JUMP_KM = 20_000;

    /**
     * Validates a reading against the locked vehicle and advances it.
     *
     * @param  Vehicle  $locked  A vehicle row already locked by the caller.
     * @param  string  $field  Which input the error belongs to.
     */
    public static function advance(Vehicle $locked, int $reading, string $field = 'odometer_km'): void
    {
        $current = (int) $locked->current_odometer_km;

        if ($reading < 0) {
            throw ValidationException::withMessages([
                $field => 'An odometer reading cannot be negative.',
            ]);
        }

        if ($reading < $current) {
            throw ValidationException::withMessages([
                $field => 'The reading is below the recorded '.number_format($current)
                    .' km. An odometer cannot go backwards — correct the existing record instead.',
            ]);
        }

        if ($reading - $current > self::MAXIMUM_JUMP_KM) {
            throw ValidationException::withMessages([
                $field => 'That is more than '.number_format(self::MAXIMUM_JUMP_KM)
                    .' km beyond the last reading of '.number_format($current).' km. Check for a typo.',
            ]);
        }

        // Equal readings are fine: two records on the same day at the same
        // mileage are ordinary, so only a strict decrease is refused.
        if ($reading > $current) {
            $locked->forceFill([
                'current_odometer_km' => $reading,
                'odometer_updated_at' => now(),
            ])->save();
        }
    }
}
