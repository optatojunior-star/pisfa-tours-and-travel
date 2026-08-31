<?php

namespace App\Actions\Corporate;

use App\Enums\CorporateAccountStatus;
use App\Enums\GroupBookingStatus;
use App\Models\CorporateAccount;
use App\Models\GroupBooking;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Corporate\CorporateCreditQuery;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Opens, suspends, and closes a company account.
 *
 * Suspension is the useful state: it stops new bookings on credit without
 * tearing up the terms or hiding the invoices already out.
 */
class TransitionCorporateAccount
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly CorporateCreditQuery $credit,
    ) {}

    public function activate(User $actor, CorporateAccount $account): CorporateAccount
    {
        return $this->moveTo($actor, $account, CorporateAccountStatus::Active, 'corporate_account.activated', [
            // Kept if it traded before, so an account suspended and restored
            // does not lose the day the relationship started.
            'activated_at' => $account->activated_at ?? now(),
            'suspended_at' => null,
            'suspension_reason' => null,
            'closed_at' => null,
            'closure_reason' => null,
        ]);
    }

    public function suspend(User $actor, CorporateAccount $account, string $reason): CorporateAccount
    {
        $reason = $this->validatedReason($reason);

        return $this->moveTo($actor, $account, CorporateAccountStatus::Suspended, 'corporate_account.suspended', [
            'suspended_at' => now(),
            'suspension_reason' => $reason,
        ]);
    }

    /**
     * Closes the relationship.
     *
     * Refused while money is owed or a trip is still to run: closing would take
     * the account off every screen that chases the debt, and would strip the
     * organiser's access to a booking PISFA has still promised to deliver.
     */
    public function close(User $actor, CorporateAccount $account, string $reason): CorporateAccount
    {
        $reason = $this->validatedReason($reason);

        return DB::transaction(function () use ($actor, $account, $reason): CorporateAccount {
            $lockedActor = CorporateAccess::lockedTermsSetter($actor);

            $locked = CorporateAccount::query()
                ->whereKey($account->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === CorporateAccountStatus::Closed) {
                return $locked;
            }

            $this->assertTransition($locked, CorporateAccountStatus::Closed);

            $position = $this->credit->position($locked);

            if ($position['outstanding_minor'] > 0) {
                throw ValidationException::withMessages([
                    'status' => 'This account still owes '
                        .Money::format($position['outstanding_minor'], $position['currency'])
                        .'. Settle or write it off before closing.',
                ]);
            }

            $liveTrips = GroupBooking::query()
                ->where('corporate_account_id', $locked->getKey())
                ->whereIn('status', [
                    GroupBookingStatus::Confirmed->value,
                    GroupBookingStatus::InProgress->value,
                ])
                ->exists();

            if ($liveTrips) {
                throw ValidationException::withMessages([
                    'status' => 'This account has trips that have not finished. '
                        .'Suspend it instead, or complete those bookings first.',
                ]);
            }

            $previous = $locked->status;

            $locked->forceFill([
                'status' => CorporateAccountStatus::Closed,
                'closed_at' => now(),
                'closure_reason' => $reason,
            ])->save();

            $this->auditLogger->record(
                event: 'corporate_account.closed',
                auditable: $locked,
                oldValues: ['status' => $previous->value],
                newValues: ['status' => CorporateAccountStatus::Closed->value],
                user: $lockedActor,
            );

            return $locked->fresh();
        }, 3);
    }

    /** Brings a closed account back as a prospect, so terms are agreed again. */
    public function reopen(User $actor, CorporateAccount $account): CorporateAccount
    {
        return $this->moveTo($actor, $account, CorporateAccountStatus::Prospect, 'corporate_account.reopened', [
            'closed_at' => null,
            'closure_reason' => null,
        ]);
    }

    /** @param array<string, mixed> $extra */
    private function moveTo(
        User $actor,
        CorporateAccount $account,
        CorporateAccountStatus $next,
        string $event,
        array $extra = [],
    ): CorporateAccount {
        return DB::transaction(function () use ($actor, $account, $next, $event, $extra): CorporateAccount {
            $lockedActor = CorporateAccess::lockedTermsSetter($actor);

            $locked = CorporateAccount::query()
                ->whereKey($account->getKey())
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
                newValues: ['status' => $next->value],
                user: $lockedActor,
            );

            return $locked->fresh();
        }, 3);
    }

    private function assertTransition(CorporateAccount $account, CorporateAccountStatus $next): void
    {
        if (! $account->canTransitionTo($next)) {
            throw ValidationException::withMessages([
                'status' => "A {$account->status->label()} account cannot become {$next->label()}.",
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
}
