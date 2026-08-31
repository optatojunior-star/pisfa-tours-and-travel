<?php

namespace App\Actions\Leasing;

use App\Enums\LeasePayoutStatus;
use App\Models\User;
use App\Models\VehicleLeasePayout;
use App\Notifications\Leasing\LeasePayoutReadyNotification;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Moves a month's money from working figure to money sent.
 *
 * Approval is manager-only and is the moment the figures stop moving: from
 * there the owner has a statement, so a correction is a new adjustment rather
 * than an edit to what they were already told.
 */
class TransitionLeasePayout
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function approve(User $actor, VehicleLeasePayout $payout): VehicleLeasePayout
    {
        return DB::transaction(function () use ($actor, $payout): VehicleLeasePayout {
            $lockedActor = LeasingAccess::lockedCommitter($actor);

            $locked = VehicleLeasePayout::query()
                ->whereKey($payout->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === LeasePayoutStatus::Approved) {
                return $locked;
            }

            $this->assertTransition($locked, LeasePayoutStatus::Approved);

            $this->apply($locked, LeasePayoutStatus::Approved, [
                'approved_at' => now(),
                'approved_by_user_id' => $lockedActor->getKey(),
            ], 'lease_payout.approved', $lockedActor);

            DB::afterCommit(fn () => $this->notify($locked));

            return $locked->fresh('lease.owner');
        }, 3);
    }

    /** Sends it back for recalculation before any money has moved. */
    public function reopen(User $actor, VehicleLeasePayout $payout, string $reason): VehicleLeasePayout
    {
        $reason = $this->validatedReason($reason);

        return DB::transaction(function () use ($actor, $payout, $reason): VehicleLeasePayout {
            $lockedActor = LeasingAccess::lockedCommitter($actor);

            $locked = VehicleLeasePayout::query()
                ->whereKey($payout->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === LeasePayoutStatus::Draft) {
                return $locked;
            }

            $this->assertTransition($locked, LeasePayoutStatus::Draft);

            $this->apply($locked, LeasePayoutStatus::Draft, [
                'approved_at' => null,
                'approved_by_user_id' => null,
                'closure_reason' => $reason,
            ], 'lease_payout.reopened', $lockedActor);

            return $locked->fresh('lease');
        }, 3);
    }

    /**
     * Records that the money actually went, with the transfer's own reference.
     *
     * The reference is required: a payout marked paid with nothing to trace it
     * back to is indistinguishable from one somebody forgot to send.
     */
    public function markPaid(User $actor, VehicleLeasePayout $payout, string $paymentReference): VehicleLeasePayout
    {
        $paymentReference = trim($paymentReference);

        Validator::make(
            ['payment_reference' => $paymentReference],
            ['payment_reference' => ['required', 'string', 'min:3', 'max:120']],
            ['payment_reference.required' => 'Record the transfer reference so the payment can be traced.'],
        )->validate();

        return DB::transaction(function () use ($actor, $payout, $paymentReference): VehicleLeasePayout {
            $lockedActor = LeasingAccess::lockedCommitter($actor);

            $locked = VehicleLeasePayout::query()
                ->whereKey($payout->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === LeasePayoutStatus::Paid) {
                return $locked;
            }

            $this->assertTransition($locked, LeasePayoutStatus::Paid);

            $this->apply($locked, LeasePayoutStatus::Paid, [
                'paid_at' => now(),
                'payment_reference' => $paymentReference,
            ], 'lease_payout.paid', $lockedActor);

            return $locked->fresh('lease');
        }, 3);
    }

    public function cancel(User $actor, VehicleLeasePayout $payout, string $reason): VehicleLeasePayout
    {
        $reason = $this->validatedReason($reason);

        return DB::transaction(function () use ($actor, $payout, $reason): VehicleLeasePayout {
            $lockedActor = LeasingAccess::lockedCommitter($actor);

            $locked = VehicleLeasePayout::query()
                ->whereKey($payout->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === LeasePayoutStatus::Cancelled) {
                return $locked;
            }

            $this->assertTransition($locked, LeasePayoutStatus::Cancelled);

            $this->apply($locked, LeasePayoutStatus::Cancelled, [
                'closure_reason' => $reason,
            ], 'lease_payout.cancelled', $lockedActor);

            return $locked->fresh('lease');
        }, 3);
    }

    /** @param array<string, mixed> $extra */
    private function apply(
        VehicleLeasePayout $payout,
        LeasePayoutStatus $next,
        array $extra,
        string $event,
        User $actor,
    ): void {
        $previous = $payout->status;

        $payout->forceFill(array_merge($extra, ['status' => $next]))->save();

        $this->auditLogger->record(
            event: $event,
            auditable: $payout,
            oldValues: ['status' => $previous->value],
            newValues: [
                'status' => $next->value,
                'net_payable_minor' => $payout->net_payable_minor,
                'currency' => $payout->currency,
            ],
            user: $actor,
        );
    }

    private function assertTransition(VehicleLeasePayout $payout, LeasePayoutStatus $next): void
    {
        if (! $payout->canTransitionTo($next)) {
            throw ValidationException::withMessages([
                'status' => "A {$payout->status->label()} payout cannot become {$next->label()}.",
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

    private function notify(VehicleLeasePayout $payout): void
    {
        $payout->loadMissing('lease.owner');

        $payout->lease?->owner?->notify(new LeasePayoutReadyNotification(
            reference: $payout->reference,
            leaseReference: (string) $payout->lease?->reference,
            period: $payout->monthLabel(),
            net: $payout->formattedNet(),
        ));
    }
}
