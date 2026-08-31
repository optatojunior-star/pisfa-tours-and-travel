<?php

namespace App\Actions\Accommodation;

use App\Enums\PropertyStatus;
use App\Models\Property;
use App\Models\PropertyBooking;
use App\Models\PropertyRoomType;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Puts a property on the public site, or takes it off.
 *
 * Manager-only, because publishing is what makes a room bookable and a price
 * chargeable.
 */
class TransitionProperty
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * A property cannot go live without something to sell.
     *
     * An empty property renders as a page with a booking form that can never
     * succeed, which is worse than not being listed at all.
     */
    public function publish(User $actor, Property $property): Property
    {
        return DB::transaction(function () use ($actor, $property): Property {
            $lockedActor = AccommodationAccess::lockedPublisher($actor);

            $locked = Property::query()
                ->whereKey($property->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === PropertyStatus::Published) {
                return $locked;
            }

            $this->assertTransition($locked, PropertyStatus::Published);

            $bookable = PropertyRoomType::query()
                ->where('property_id', $locked->getKey())
                ->active()
                ->where('quantity', '>=', 1)
                ->whereHas('rates', fn ($rates) => $rates->where('is_active', true))
                ->exists();

            if (! $bookable) {
                throw ValidationException::withMessages([
                    'status' => 'Add at least one active room type with a price before publishing.',
                ]);
            }

            $previous = $locked->status;

            $locked->forceFill([
                'status' => PropertyStatus::Published,
                // Kept if it was published before, so a property taken down and
                // put back does not lose the day it first appeared.
                'published_at' => $locked->published_at ?? now(),
                'updated_by_user_id' => $lockedActor->getKey(),
            ])->save();

            $this->auditLogger->record(
                event: 'property.published',
                auditable: $locked,
                oldValues: ['status' => $previous->value],
                newValues: ['status' => PropertyStatus::Published->value],
                user: $lockedActor,
            );

            return $locked->fresh();
        }, 3);
    }

    /** Takes it off the public site without touching bookings already made. */
    public function unpublish(User $actor, Property $property): Property
    {
        return $this->moveTo($actor, $property, PropertyStatus::Draft, 'property.unpublished');
    }

    /**
     * Retires a property.
     *
     * Refused while guests are still expected: archiving would hide the page a
     * confirmed booking links to, and PISFA would still owe those guests a room.
     */
    public function archive(User $actor, Property $property): Property
    {
        return DB::transaction(function () use ($actor, $property): Property {
            $lockedActor = AccommodationAccess::lockedPublisher($actor);

            $locked = Property::query()
                ->whereKey($property->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === PropertyStatus::Archived) {
                return $locked;
            }

            $this->assertTransition($locked, PropertyStatus::Archived);

            $liveStays = PropertyBooking::query()
                ->where('property_id', $locked->getKey())
                ->holdingInventory()
                ->where('check_out_date', '>=', now()->toDateString())
                ->exists();

            if ($liveStays) {
                throw ValidationException::withMessages([
                    'status' => 'This property has stays that have not finished. '
                        .'Unpublish it instead, or settle those bookings first.',
                ]);
            }

            $previous = $locked->status;

            $locked->forceFill([
                'status' => PropertyStatus::Archived,
                'updated_by_user_id' => $lockedActor->getKey(),
            ])->save();

            $this->auditLogger->record(
                event: 'property.archived',
                auditable: $locked,
                oldValues: ['status' => $previous->value],
                newValues: ['status' => PropertyStatus::Archived->value],
                user: $lockedActor,
            );

            return $locked->fresh();
        }, 3);
    }

    /** Brings an archived property back as a draft, to be looked at again. */
    public function restore(User $actor, Property $property): Property
    {
        return $this->moveTo($actor, $property, PropertyStatus::Draft, 'property.restored');
    }

    private function moveTo(User $actor, Property $property, PropertyStatus $next, string $event): Property
    {
        return DB::transaction(function () use ($actor, $property, $next, $event): Property {
            $lockedActor = AccommodationAccess::lockedPublisher($actor);

            $locked = Property::query()
                ->whereKey($property->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === $next) {
                return $locked;
            }

            $this->assertTransition($locked, $next);

            $previous = $locked->status;

            $locked->forceFill([
                'status' => $next,
                'updated_by_user_id' => $lockedActor->getKey(),
            ])->save();

            $this->auditLogger->record(
                event: $event,
                auditable: $locked,
                oldValues: ['status' => $previous->value],
                newValues: ['status' => $next->value],
                user: $lockedActor,
            );

            return $locked->fresh();
        }, 3);
    }

    private function assertTransition(Property $property, PropertyStatus $next): void
    {
        if (! $property->canTransitionTo($next)) {
            throw ValidationException::withMessages([
                'status' => "A {$property->status->label()} property cannot become {$next->label()}.",
            ]);
        }
    }
}
