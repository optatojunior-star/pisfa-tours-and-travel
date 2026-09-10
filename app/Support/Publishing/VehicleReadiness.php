<?php

namespace App\Support\Publishing;

use App\Enums\HireMode;
use App\Models\Vehicle;
use App\Models\VehicleHireRate;
use App\Models\VehicleMedia;
use DateTimeInterface;
use Illuminate\Support\Collection;

/**
 * What a hire vehicle needs before the public catalogue will show it.
 *
 * This exists because of one sentence: "A published vehicle needs a currently
 * effective supported rate for at least one hire mode." Every word of that is
 * accurate and the whole of it is unusable. It does not say which of the five
 * separate conditions failed — the price is missing, or it is switched off, or
 * its date range has not started, or it has expired, or both amounts are blank
 * — and those have different fixes.
 *
 * So the conditions are pulled apart and reported one at a time, in the words
 * somebody running a hire desk would use.
 */
final class VehicleReadiness
{
    public static function for(Vehicle $vehicle, ?DateTimeInterface $at = null): Readiness
    {
        $at ??= now();
        $media = $vehicle->relationLoaded('media') ? $vehicle->media : $vehicle->media()->get();
        $rates = $vehicle->exists
            ? VehicleHireRate::query()->where('vehicle_id', $vehicle->getKey())->get()
            : new Collection;

        return new Readiness([
            self::photographCheck($media),
            self::coverCheck($media),
            self::rateCheck($rates, $at),
        ]);
    }

    /** @param Collection<int, VehicleMedia> $media */
    private static function photographCheck(Collection $media): ReadinessCheck
    {
        if ($media->isNotEmpty()) {
            return ReadinessCheck::pass(
                'photographs',
                'At least one photograph',
                'images',
                $media->count().' uploaded.',
            );
        }

        return ReadinessCheck::fail(
            key: 'photographs',
            label: 'At least one photograph',
            field: 'images',
            problem: 'This vehicle has no photographs.',
            fix: 'Open Edit vehicle and upload at least one picture of it.',
            anchor: 'vehicle-media-heading',
            actionLabel: 'Add photographs',
        );
    }

    /** @param Collection<int, VehicleMedia> $media */
    private static function coverCheck(Collection $media): ReadinessCheck
    {
        $covers = $media->where('is_cover', true)->count();

        if ($covers === 1) {
            return ReadinessCheck::pass(
                'cover',
                'Exactly one cover photograph',
                'images',
                'One picture is set as the cover.',
            );
        }

        if ($covers === 0) {
            return ReadinessCheck::fail(
                key: 'cover',
                label: 'Exactly one cover photograph',
                field: 'images',
                problem: $media->isEmpty()
                    ? 'No photograph is set as the catalogue cover, because none has been uploaded.'
                    : 'None of the '.$media->count().' photographs is set as the catalogue cover.',
                fix: 'Upload a photograph — the first one on a vehicle with no cover becomes the cover automatically.',
                anchor: 'vehicle-media-heading',
                actionLabel: 'Add photographs',
            );
        }

        return ReadinessCheck::fail(
            key: 'cover',
            label: 'Exactly one cover photograph',
            field: 'images',
            problem: $covers.' photographs are marked as the cover, and only one can be.',
            fix: 'Remove the extra ones and leave a single picture for the catalogue card.',
            anchor: 'vehicle-media-heading',
            actionLabel: 'Fix the photographs',
        );
    }

    /**
     * The rate rule, unpacked.
     *
     * A price has to clear five hurdles at once and the old message named none
     * of them. Each is now reported separately, in the order somebody would fix
     * them, so the reader is told the one thing that is actually wrong.
     *
     * @param  Collection<int, VehicleHireRate>  $rates
     */
    private static function rateCheck(Collection $rates, DateTimeInterface $at): ReadinessCheck
    {
        $label = 'A daily price that applies today';
        $currencies = array_values((array) config('car_hire.currencies', ['UGX', 'USD']));
        $timezone = (string) config('pisfa.business_timezone', 'Africa/Kampala');

        $fail = static fn (string $problem, string $fix, string $action): ReadinessCheck => ReadinessCheck::fail(
            key: 'rate',
            label: $label,
            field: 'rates',
            problem: $problem,
            fix: $fix,
            anchor: 'new-rate-heading',
            actionLabel: $action,
        );

        if ($rates->isEmpty()) {
            return $fail(
                'This vehicle has no daily price yet.',
                'Add one under "Add rate version" below: fill in the self-drive price, the with-driver price, or both.',
                'Add a price',
            );
        }

        $priced = $rates->filter(static fn (VehicleHireRate $rate): bool => (int) ($rate->self_drive_daily_minor ?? 0) > 0
            || (int) ($rate->with_driver_daily_minor ?? 0) > 0);

        if ($priced->isEmpty()) {
            return $fail(
                'Every price on this vehicle has both the self-drive and the with-driver amount left blank, so there is nothing to charge.',
                'Add a new price with an amount filled in for at least one of the two hire modes.',
                'Add a price',
            );
        }

        $rightCurrency = $priced->filter(
            static fn (VehicleHireRate $rate): bool => in_array($rate->currency, $currencies, true),
        );

        if ($rightCurrency->isEmpty()) {
            return $fail(
                'The prices on this vehicle are in '.$priced->pluck('currency')->unique()->join(' and ')
                    .', which the public catalogue does not sell in.',
                'Add a price in '.implode(' or ', $currencies).'.',
                'Add a price',
            );
        }

        $active = $rightCurrency->where('is_active', true);

        if ($active->isEmpty()) {
            return $fail(
                'This vehicle has a price, but it is switched off.',
                'Add a new price version — a switched-off one is kept for the record and is never charged.',
                'Add a price',
            );
        }

        $started = $active->filter(static fn (VehicleHireRate $rate): bool => ! $rate->effective_from->isAfter($at));

        if ($started->isEmpty()) {
            /** @var VehicleHireRate $soonest */
            $soonest = $active->sortBy('effective_from')->first();

            return $fail(
                'The price on this vehicle does not start until '
                    .$soonest->effective_from->timezone($timezone)->format('j M Y, H:i').'.',
                'Either wait until then, or add a price whose "Effective from" is today or earlier.',
                'Add a price starting now',
            );
        }

        $current = $started->filter(
            static fn (VehicleHireRate $rate): bool => $rate->effective_until === null
                || $rate->effective_until->isAfter($at),
        );

        if ($current->isEmpty()) {
            /** @var VehicleHireRate $latest */
            $latest = $started->sortByDesc('effective_until')->first();

            return $fail(
                'The price on this vehicle expired on '
                    .$latest->effective_until?->timezone($timezone)->format('j M Y, H:i').'.',
                'Add a new price version, and leave "Effective until" empty so it does not expire again.',
                'Add a current price',
            );
        }

        $modes = [];

        foreach ($current as $rate) {
            foreach (HireMode::cases() as $mode) {
                if ((int) ($rate->rateFor($mode) ?? 0) > 0) {
                    $modes[$mode->value] = $mode->label();
                }
            }
        }

        return ReadinessCheck::pass('rate', $label, 'rates', 'Priced for '.implode(' and ', $modes).'.');
    }
}
