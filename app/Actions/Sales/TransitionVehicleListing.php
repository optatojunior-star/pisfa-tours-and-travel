<?php

namespace App\Actions\Sales;

use App\Enums\ListingStatus;
use App\Enums\SalesEnquiryStatus;
use App\Enums\VehicleCatalogueStatus;
use App\Enums\VehicleOperationalStatus;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleListing;
use App\Models\VehicleSalesEnquiry;
use App\Services\AuditLogger;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Moves a listing through its sale.
 *
 * Selling a fleet vehicle has a consequence beyond the listing: the car must
 * leave the hire catalogue in the same transaction. A showroom that marked a
 * car sold while the hire site still took bookings for it would be selling the
 * same asset twice.
 */
class TransitionVehicleListing
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /** Puts the listing in the public showroom. */
    public function list(User $actor, VehicleListing $listing): VehicleListing
    {
        return $this->moveTo($actor, $listing, ListingStatus::Available, 'vehicle_listing.listed', [
            'listed_at' => now(),
            'withdrawn_at' => null,
            'closure_reason' => null,
        ]);
    }

    /** Takes it off the market while a deposit is held. */
    public function reserve(User $actor, VehicleListing $listing, ?VehicleSalesEnquiry $for = null): VehicleListing
    {
        return $this->moveTo($actor, $listing, ListingStatus::Reserved, 'vehicle_listing.reserved', [
            'reserved_at' => now(),
        ], $for);
    }

    /** A reservation that fell through returns the car to the market. */
    public function release(User $actor, VehicleListing $listing): VehicleListing
    {
        return $this->moveTo($actor, $listing, ListingStatus::Available, 'vehicle_listing.released', [
            'reserved_at' => null,
        ]);
    }

    /**
     * Brings a withdrawn listing back as a draft.
     *
     * Draft rather than straight back to Available: a car that came off the
     * market has usually changed — price, condition, or photographs — and it
     * should be looked at again before the public sees it.
     */
    public function restore(User $actor, VehicleListing $listing): VehicleListing
    {
        return $this->moveTo($actor, $listing, ListingStatus::Draft, 'vehicle_listing.restored', [
            'withdrawn_at' => null,
            'closure_reason' => null,
        ]);
    }

    public function withdraw(User $actor, VehicleListing $listing, string $reason): VehicleListing
    {
        $reason = trim($reason);

        Validator::make(
            ['reason' => $reason],
            ['reason' => ['required', 'string', 'min:5', 'max:255']],
        )->validate();

        return $this->moveTo($actor, $listing, ListingStatus::Withdrawn, 'vehicle_listing.withdrawn', [
            'withdrawn_at' => now(),
            'closure_reason' => $reason,
        ]);
    }

    /**
     * Records the sale, at the price it actually fetched.
     *
     * Restricted to managers: this figure is what every sales report is built
     * from, and it is final — a mistaken sale is corrected by a new listing so
     * the record of what was sold and when is never rewritten.
     */
    public function sell(
        User $actor,
        VehicleListing $listing,
        string $soldPrice,
        ?VehicleSalesEnquiry $buyer = null,
    ): VehicleListing {
        return DB::transaction(function () use ($actor, $listing, $soldPrice, $buyer): VehicleListing {
            $lockedActor = SalesAccess::lockedCloser($actor);

            [$locked, $vehicle] = $this->lockPair($listing);

            if ($locked->status === ListingStatus::Sold) {
                return $locked;
            }

            $this->assertTransition($locked, ListingStatus::Sold);

            try {
                $price = Money::parse($soldPrice, $locked->currency);
            } catch (InvalidArgumentException $exception) {
                throw ValidationException::withMessages(['sold_price' => $exception->getMessage()]);
            }

            if ($price < 1) {
                throw ValidationException::withMessages([
                    'sold_price' => 'Record what the vehicle actually sold for.',
                ]);
            }

            $lockedBuyer = $this->lockedBuyer($locked, $buyer);

            $locked->forceFill([
                'status' => ListingStatus::Sold,
                'sold_at' => now(),
                'sold_price_minor' => $price,
                'sold_to_enquiry_id' => $lockedBuyer?->getKey(),
            ])->save();

            // The buyer's enquiry is won; every other open one on this listing
            // is lost, because the car is gone.
            $this->closeEnquiries($locked, $lockedBuyer);

            // The car leaves the hire catalogue in the same transaction, so it
            // cannot be sold and booked at the same moment.
            $this->retireFromFleet($vehicle);

            $this->auditLogger->record(
                event: 'vehicle_listing.sold',
                auditable: $locked,
                newValues: [
                    'reference' => $locked->reference,
                    'asking_price_minor' => $locked->asking_price_minor,
                    'sold_price_minor' => $price,
                    'currency' => $locked->currency,
                    'vehicle_id' => $locked->vehicle_id,
                    'buyer_enquiry' => $lockedBuyer?->reference,
                ],
                user: $lockedActor,
            );

            return $locked->fresh(['vehicle', 'enquiries']);
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function moveTo(
        User $actor,
        VehicleListing $listing,
        ListingStatus $next,
        string $event,
        array $extra = [],
        ?VehicleSalesEnquiry $related = null,
    ): VehicleListing {
        return DB::transaction(function () use ($actor, $listing, $next, $event, $extra, $related): VehicleListing {
            $lockedActor = SalesAccess::lockedManager($actor);

            $locked = VehicleListing::query()
                ->whereKey($listing->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === $next) {
                return $locked;
            }

            $this->assertTransition($locked, $next);

            $previous = $locked->status;

            $locked->forceFill(array_merge($extra, ['status' => $next]))->save();

            $this->auditLogger->record(
                event: $event,
                auditable: $locked,
                oldValues: ['status' => $previous->value],
                newValues: array_filter([
                    'status' => $next->value,
                    'enquiry' => $related?->reference,
                ]),
                user: $lockedActor,
            );

            return $locked->fresh(['vehicle', 'enquiries']);
        }, 3);
    }

    /**
     * A buyer must be an enquiry on this listing.
     *
     * Attributing a sale to somebody who enquired about a different car would
     * corrupt both the listing's record and that person's history.
     */
    private function lockedBuyer(VehicleListing $listing, ?VehicleSalesEnquiry $buyer): ?VehicleSalesEnquiry
    {
        if ($buyer === null) {
            return null;
        }

        $locked = VehicleSalesEnquiry::query()
            ->whereKey($buyer->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        if ((int) $locked->vehicle_listing_id !== (int) $listing->getKey()) {
            throw ValidationException::withMessages([
                'buyer' => 'That enquiry belongs to a different listing.',
            ]);
        }

        return $locked;
    }

    private function closeEnquiries(VehicleListing $listing, ?VehicleSalesEnquiry $buyer): void
    {
        VehicleSalesEnquiry::query()
            ->where('vehicle_listing_id', $listing->getKey())
            ->open()
            ->get()
            ->each(function (VehicleSalesEnquiry $enquiry) use ($buyer): void {
                $isBuyer = $buyer !== null && $enquiry->is($buyer);
                $next = $isBuyer ? SalesEnquiryStatus::Won : SalesEnquiryStatus::Lost;

                // The graph may not allow the jump from every open state; when
                // it does not, the enquiry is closed as lost from wherever it
                // is rather than left dangling on a sold car.
                if (! $enquiry->canTransitionTo($next)) {
                    $next = SalesEnquiryStatus::Lost;
                }

                if (! $enquiry->canTransitionTo($next)) {
                    return;
                }

                $enquiry->forceFill([
                    'status' => $next,
                    'closed_at' => now(),
                    'closure_reason' => $isBuyer
                        ? 'Purchased this vehicle.'
                        : 'The vehicle was sold to another buyer.',
                ])->save();
            });
    }

    /**
     * Takes a sold fleet vehicle out of hire.
     *
     * Unpublished *and* retired: leaving it published would keep it in search
     * results, and leaving it operational would keep it assignable.
     */
    private function retireFromFleet(?Vehicle $vehicle): void
    {
        if ($vehicle === null) {
            return;
        }

        $vehicle->forceFill([
            'catalogue_status' => VehicleCatalogueStatus::Archived,
            'operational_status' => VehicleOperationalStatus::Retired,
        ])->save();
    }

    /** @return array{0: VehicleListing, 1: Vehicle|null} */
    private function lockPair(VehicleListing $listing): array
    {
        // Vehicle before listing, matching the lock order used across the fleet
        // actions, so concurrent work cannot deadlock.
        $vehicle = $listing->vehicle_id === null ? null : Vehicle::query()
            ->whereKey($listing->vehicle_id)
            ->lockForUpdate()
            ->first();

        $locked = VehicleListing::query()
            ->whereKey($listing->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        return [$locked, $vehicle];
    }

    private function assertTransition(VehicleListing $listing, ListingStatus $next): void
    {
        if (! $listing->canTransitionTo($next)) {
            throw ValidationException::withMessages([
                'status' => "A {$listing->status->label()} listing cannot become {$next->label()}.",
            ]);
        }
    }
}
