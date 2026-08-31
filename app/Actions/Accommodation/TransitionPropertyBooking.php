<?php

namespace App\Actions\Accommodation;

use App\Enums\PropertyBookingStatus;
use App\Models\PropertyBooking;
use App\Models\PropertyRoomType;
use App\Models\User;
use App\Notifications\Accommodation\PropertyBookingUpdatedNotification;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Moves a stay through its life.
 *
 * Confirmation re-checks availability. A pending booking whose hold has quietly
 * expired may have had its rooms taken by somebody else in the meantime, and
 * confirming it anyway would promise a room that is gone.
 */
class TransitionPropertyBooking
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function confirm(User $actor, PropertyBooking $booking): PropertyBooking
    {
        return DB::transaction(function () use ($actor, $booking): PropertyBooking {
            $lockedActor = AccommodationAccess::lockedManager($actor);

            [$locked, $roomType] = $this->lockPair($booking);

            if ($locked->status === PropertyBookingStatus::Confirmed) {
                return $locked;
            }

            $this->assertTransition($locked, PropertyBookingStatus::Confirmed);

            // Excluding itself: its own rooms are already in the committed
            // count while it is pending and unexpired, and counting them twice
            // would make every confirmation look oversold.
            $available = $roomType->availableRooms(
                $locked->check_in_date,
                $locked->check_out_date,
                $locked->getKey(),
            );

            if ($available < $locked->rooms) {
                throw ValidationException::withMessages([
                    'status' => 'Those rooms are no longer free for these dates. '
                        .'Only '.$available.' left, and this booking needs '.$locked->rooms.'.',
                ]);
            }

            $this->apply($locked, PropertyBookingStatus::Confirmed, [
                'confirmed_at' => now(),
                // A confirmed stay is not a hold any more; leaving the timestamp
                // would let the expiry sweep read it as stale work.
                'hold_expires_at' => null,
            ], 'property_booking.confirmed', $lockedActor);

            DB::afterCommit(fn () => $this->notify($locked, 'Your stay is confirmed.'));

            return $locked->fresh(['property', 'roomType']);
        }, 3);
    }

    public function decline(User $actor, PropertyBooking $booking, string $reason): PropertyBooking
    {
        $reason = $this->validatedReason($reason);

        return DB::transaction(function () use ($actor, $booking, $reason): PropertyBooking {
            $lockedActor = AccommodationAccess::lockedManager($actor);

            [$locked] = $this->lockPair($booking);

            if ($locked->status === PropertyBookingStatus::Declined) {
                return $locked;
            }

            $this->assertTransition($locked, PropertyBookingStatus::Declined);

            $this->apply($locked, PropertyBookingStatus::Declined, [
                'closure_reason' => $reason,
                'cancelled_at' => now(),
                'hold_expires_at' => null,
            ], 'property_booking.declined', $lockedActor);

            DB::afterCommit(fn () => $this->notify($locked, 'We could not take this booking: '.$reason));

            return $locked->fresh(['property', 'roomType']);
        }, 3);
    }

    /**
     * Cancels a stay.
     *
     * Staff may cancel at any point up to arrival; a guest may only cancel
     * before the property's own cutoff, which is why the caller says which of
     * the two this is rather than the action guessing from the actor's role.
     */
    public function cancel(
        User $actor,
        PropertyBooking $booking,
        string $reason,
        bool $byCustomer = false,
    ): PropertyBooking {
        $reason = $this->validatedReason($reason);

        return DB::transaction(function () use ($actor, $booking, $reason, $byCustomer): PropertyBooking {
            $lockedActor = $byCustomer
                ? User::query()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail()
                : AccommodationAccess::lockedManager($actor);

            [$locked] = $this->lockPair($booking);

            if ($locked->status === PropertyBookingStatus::Cancelled) {
                return $locked;
            }

            if ($byCustomer && (int) $locked->customer_id !== (int) $lockedActor->getKey()) {
                throw ValidationException::withMessages([
                    'status' => 'That booking belongs to somebody else.',
                ]);
            }

            $this->assertTransition($locked, PropertyBookingStatus::Cancelled);

            if ($byCustomer && ! $locked->isWithinFreeCancellation()) {
                throw ValidationException::withMessages([
                    'status' => 'The free-cancellation window for this stay has closed. '
                        .'Please contact us so we can look at it with the property.',
                ]);
            }

            $this->apply($locked, PropertyBookingStatus::Cancelled, [
                'closure_reason' => $reason,
                'cancelled_at' => now(),
                'hold_expires_at' => null,
            ], 'property_booking.cancelled', $lockedActor);

            DB::afterCommit(fn () => $this->notify($locked, 'This stay has been cancelled.'));

            return $locked->fresh(['property', 'roomType']);
        }, 3);
    }

    public function checkIn(User $actor, PropertyBooking $booking): PropertyBooking
    {
        return $this->deskStep(
            $actor,
            $booking,
            PropertyBookingStatus::CheckedIn,
            ['checked_in_at' => now()],
            'property_booking.checked_in',
        );
    }

    public function checkOut(User $actor, PropertyBooking $booking): PropertyBooking
    {
        return $this->deskStep(
            $actor,
            $booking,
            PropertyBookingStatus::CheckedOut,
            ['checked_out_at' => now()],
            'property_booking.checked_out',
        );
    }

    /** @param array<string, mixed> $extra */
    private function deskStep(
        User $actor,
        PropertyBooking $booking,
        PropertyBookingStatus $next,
        array $extra,
        string $event,
    ): PropertyBooking {
        return DB::transaction(function () use ($actor, $booking, $next, $extra, $event): PropertyBooking {
            $lockedActor = AccommodationAccess::lockedManager($actor);

            [$locked] = $this->lockPair($booking);

            if ($locked->status === $next) {
                return $locked;
            }

            $this->assertTransition($locked, $next);

            $this->apply($locked, $next, $extra, $event, $lockedActor);

            return $locked->fresh(['property', 'roomType']);
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function apply(
        PropertyBooking $booking,
        PropertyBookingStatus $next,
        array $extra,
        string $event,
        User $actor,
    ): void {
        $previous = $booking->status;

        $booking->forceFill(array_merge($extra, ['status' => $next]))->save();

        $this->auditLogger->record(
            event: $event,
            auditable: $booking,
            oldValues: ['status' => $previous->value],
            newValues: ['status' => $next->value],
            user: $actor,
        );
    }

    /**
     * Room type before booking, one fixed order across the domain.
     *
     * @return array{0: PropertyBooking, 1: PropertyRoomType}
     */
    private function lockPair(PropertyBooking $booking): array
    {
        $roomType = PropertyRoomType::query()
            ->whereKey($booking->property_room_type_id)
            ->lockForUpdate()
            ->firstOrFail();

        $locked = PropertyBooking::query()
            ->whereKey($booking->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        return [$locked, $roomType];
    }

    private function assertTransition(PropertyBooking $booking, PropertyBookingStatus $next): void
    {
        if (! $booking->canTransitionTo($next)) {
            throw ValidationException::withMessages([
                'status' => "A {$booking->status->label()} stay cannot become {$next->label()}.",
            ]);
        }
    }

    private function validatedReason(string $reason): string
    {
        $reason = trim($reason);

        Validator::make(
            ['reason' => $reason],
            ['reason' => ['required', 'string', 'min:5', 'max:255']],
        )->validate();

        return $reason;
    }

    private function notify(PropertyBooking $booking, string $message): void
    {
        $booking->loadMissing('customer');

        $booking->customer?->notify(new PropertyBookingUpdatedNotification(
            bookingReference: $booking->reference,
            propertyName: $booking->property_name_snapshot,
            stay: $booking->stayLabel(),
            statusLabel: $booking->status->label(),
            message: $message,
        ));
    }
}
