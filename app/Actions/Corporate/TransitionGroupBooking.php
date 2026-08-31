<?php

namespace App\Actions\Corporate;

use App\Enums\GroupBookingStatus;
use App\Models\CorporateAccount;
use App\Models\GroupBooking;
use App\Models\User;
use App\Notifications\Corporate\GroupBookingUpdatedNotification;
use App\Services\AuditLogger;
use App\Services\Corporate\CorporateCreditQuery;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Moves a group trip through its life.
 *
 * Confirmation is the interesting step: it is refused unless the manifest is
 * complete and — for a company booking on credit — unless the account can still
 * carry the amount. Both are re-checked here rather than trusted from whatever
 * screen was rendered when the price was agreed.
 */
class TransitionGroupBooking
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly CorporateCreditQuery $credit,
    ) {}

    public function quote(User $actor, GroupBooking $booking): GroupBooking
    {
        return DB::transaction(function () use ($actor, $booking): GroupBooking {
            $lockedActor = CorporateAccess::lockedManager($actor);

            $locked = $this->locked($booking);

            if ($locked->status === GroupBookingStatus::Quoted) {
                return $locked;
            }

            $this->assertTransition($locked, GroupBookingStatus::Quoted);

            // Quoting without a price is not quoting.
            if ($locked->quoted_total_minor === null || $locked->quoted_total_minor < 1) {
                throw ValidationException::withMessages([
                    'quoted_total' => 'Put a price on the group before quoting it.',
                ]);
            }

            $this->apply($locked, GroupBookingStatus::Quoted, [], 'group_booking.quoted', $lockedActor);

            DB::afterCommit(fn () => $this->notify($locked, 'We have priced your group: '
                .$locked->formattedQuotedTotal().'.'));

            return $locked->fresh(['account', 'organiser']);
        }, 3);
    }

    /** The customer accepted the price; now PISFA needs the names. */
    public function requestManifest(User $actor, GroupBooking $booking): GroupBooking
    {
        return DB::transaction(function () use ($actor, $booking): GroupBooking {
            $lockedActor = CorporateAccess::lockedManager($actor);

            $locked = $this->locked($booking);

            if ($locked->status === GroupBookingStatus::ManifestPending) {
                return $locked;
            }

            $this->assertTransition($locked, GroupBookingStatus::ManifestPending);

            $this->apply($locked, GroupBookingStatus::ManifestPending, [], 'group_booking.manifest_requested', $lockedActor);

            DB::afterCommit(fn () => $this->notify(
                $locked,
                'Please send us the names of all '.$locked->headcount.' travellers so we can confirm the trip.',
            ));

            return $locked->fresh(['account', 'organiser']);
        }, 3);
    }

    /**
     * Confirms the trip.
     *
     * Two gates, both re-checked under the lock: every seat has a name, and a
     * company booking on credit still fits inside its limit.
     */
    public function confirm(User $actor, GroupBooking $booking): GroupBooking
    {
        return DB::transaction(function () use ($actor, $booking): GroupBooking {
            $lockedActor = CorporateAccess::lockedManager($actor);

            $locked = $this->locked($booking);

            if ($locked->status === GroupBookingStatus::Confirmed) {
                return $locked;
            }

            $this->assertTransition($locked, GroupBookingStatus::Confirmed);

            if (! $locked->manifestIsComplete()) {
                throw ValidationException::withMessages([
                    'status' => 'The list has '.$locked->manifestCount().' of '.$locked->headcount
                        .' names. '.$locked->manifestShortfall().' '
                        .str('person')->plural($locked->manifestShortfall())
                        .' would travel with nothing booked for them.',
                ]);
            }

            $this->assertCreditAllows($locked);

            $this->apply($locked, GroupBookingStatus::Confirmed, [
                'confirmed_at' => now(),
            ], 'group_booking.confirmed', $lockedActor);

            DB::afterCommit(fn () => $this->notify($locked, 'Your group trip is confirmed.'));

            return $locked->fresh(['account', 'organiser']);
        }, 3);
    }

    public function start(User $actor, GroupBooking $booking): GroupBooking
    {
        return $this->deskStep($actor, $booking, GroupBookingStatus::InProgress, 'group_booking.started');
    }

    public function complete(User $actor, GroupBooking $booking): GroupBooking
    {
        return $this->deskStep($actor, $booking, GroupBookingStatus::Completed, 'group_booking.completed');
    }

    public function cancel(User $actor, GroupBooking $booking, string $reason): GroupBooking
    {
        $reason = $this->validatedReason($reason);

        return DB::transaction(function () use ($actor, $booking, $reason): GroupBooking {
            $lockedActor = CorporateAccess::lockedManager($actor);

            $locked = $this->locked($booking);

            if ($locked->status === GroupBookingStatus::Cancelled) {
                return $locked;
            }

            $this->assertTransition($locked, GroupBookingStatus::Cancelled);

            $this->apply($locked, GroupBookingStatus::Cancelled, [
                'cancelled_at' => now(),
                'closure_reason' => $reason,
            ], 'group_booking.cancelled', $lockedActor);

            DB::afterCommit(fn () => $this->notify($locked, 'This group trip has been cancelled: '.$reason));

            return $locked->fresh(['account', 'organiser']);
        }, 3);
    }

    /**
     * A company booking on credit must still fit inside its limit.
     *
     * Checked at confirmation rather than at enquiry, because that is the point
     * PISFA commits — and because the position may have moved since the price
     * was agreed. Computed from live invoices, never a stored balance.
     */
    private function assertCreditAllows(GroupBooking $booking): void
    {
        $booking->loadMissing('account');
        $account = $booking->account;

        if ($account === null || ! $account->hasCredit() || $booking->quoted_total_minor === null) {
            return;
        }

        if (! $account->status->canTrade()) {
            throw ValidationException::withMessages([
                'status' => 'The company account is '.mb_strtolower($account->status->label())
                    .' and cannot take a confirmed booking.',
            ]);
        }

        if (! $this->credit->canCarry($account, $booking->quoted_total_minor, $booking->currency)) {
            throw ValidationException::withMessages([
                'status' => $this->creditRefusal($account, $booking),
            ]);
        }
    }

    private function creditRefusal(CorporateAccount $account, GroupBooking $booking): string
    {
        if (strtoupper($booking->currency) !== strtoupper($account->currency)) {
            return 'This group is priced in '.$booking->currency.' but the account is billed in '
                .$account->currency.'. Credit is never converted, so this one has to be settled up front.';
        }

        $position = $this->credit->position($account);

        return 'This would take the account past its credit limit. '
            .Money::format($position['available_minor'], $position['currency']).' is available and the group is '
            .$booking->formattedQuotedTotal().'.';
    }

    private function deskStep(User $actor, GroupBooking $booking, GroupBookingStatus $next, string $event): GroupBooking
    {
        return DB::transaction(function () use ($actor, $booking, $next, $event): GroupBooking {
            $lockedActor = CorporateAccess::lockedManager($actor);

            $locked = $this->locked($booking);

            if ($locked->status === $next) {
                return $locked;
            }

            $this->assertTransition($locked, $next);

            $this->apply($locked, $next, [], $event, $lockedActor);

            return $locked->fresh(['account', 'organiser']);
        }, 3);
    }

    /** @param array<string, mixed> $extra */
    private function apply(
        GroupBooking $booking,
        GroupBookingStatus $next,
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
            newValues: [
                'status' => $next->value,
                'headcount' => $booking->headcount,
                'quoted_total_minor' => $booking->quoted_total_minor,
                'currency' => $booking->currency,
            ],
            user: $actor,
        );
    }

    private function locked(GroupBooking $booking): GroupBooking
    {
        return GroupBooking::query()
            ->whereKey($booking->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function assertTransition(GroupBooking $booking, GroupBookingStatus $next): void
    {
        if (! $booking->canTransitionTo($next)) {
            throw ValidationException::withMessages([
                'status' => "A {$booking->status->label()} group cannot become {$next->label()}.",
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

    private function notify(GroupBooking $booking, string $message): void
    {
        $booking->loadMissing('organiser');

        $booking->organiser?->notify(new GroupBookingUpdatedNotification(
            reference: $booking->reference,
            title: $booking->title,
            statusLabel: $booking->status->label(),
            dates: $booking->dateLabel(),
            message: $message,
        ));
    }
}
