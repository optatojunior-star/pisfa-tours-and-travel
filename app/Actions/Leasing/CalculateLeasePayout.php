<?php

namespace App\Actions\Leasing;

use App\Enums\CarHireBookingStatus;
use App\Enums\LeasePayoutModel;
use App\Enums\LeasePayoutStatus;
use App\Models\CarHireBooking;
use App\Models\User;
use App\Models\VehicleLease;
use App\Models\VehicleLeasePayout;
use App\Services\AuditLogger;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Works out what an owner is owed for one month.
 *
 * Three properties decide the whole calculation:
 *
 *  - **The security deposit is not revenue.** It is the customer's money held
 *    and returned, so the share is taken from `rental_subtotal_minor`, never
 *    from the booking total. Sharing a deposit would pay the owner out of money
 *    PISFA is holding on somebody else's behalf.
 *  - **Money is never summed across currencies.** A lease is denominated in one
 *    currency; hires in another are counted and reported as excluded rather than
 *    converted at a rate nobody agreed, or silently dropped.
 *  - **The share is integer basis points.** 25% is 2500, and the multiplication
 *    is integer arithmetic with the division last, so no rounding drift creeps
 *    into somebody's income.
 */
class CalculateLeasePayout
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * Draws up the payout for the month containing the given date.
     *
     * Idempotent by construction: the unique index on `(lease, period_start)`
     * means a second run for the same month finds the existing row rather than
     * paying the owner twice.
     */
    public function forMonth(User $actor, VehicleLease $lease, string $anyDateInMonth): VehicleLeasePayout
    {
        $month = CarbonImmutable::parse($anyDateInMonth)->startOfMonth();
        $periodStart = $month->toDateString();
        $periodEnd = $month->endOfMonth()->toDateString();

        return DB::transaction(function () use ($actor, $lease, $periodStart, $periodEnd): VehicleLeasePayout {
            $lockedActor = LeasingAccess::lockedManager($actor);

            $locked = VehicleLease::query()
                ->whereKey($lease->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            // whereDate, not where: the date cast writes a midnight time
            // component, so an equality match on the bare date would silently
            // find nothing and draw up a second payout for the same month.
            $existing = VehicleLeasePayout::query()
                ->where('vehicle_lease_id', $locked->getKey())
                ->whereDate('period_start', $periodStart)
                ->lockForUpdate()
                ->first();

            // Only a draft is recomputed. Once it is approved somebody has been
            // told what they are getting, and the figures stop moving.
            if ($existing !== null && ! $existing->status->isEditable()) {
                return $existing;
            }

            $basis = $this->basisFor($locked, $periodStart, $periodEnd);

            $payout = $existing ?? new VehicleLeasePayout;

            $payout->forceFill(array_merge($basis, [
                'reference' => $payout->reference ?? 'PAY-'.Str::upper((string) Str::ulid()),
                'vehicle_lease_id' => $locked->getKey(),
                'status' => LeasePayoutStatus::Draft,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'currency' => $locked->currency,
            ]))->save();

            $this->auditLogger->record(
                event: $existing === null ? 'lease_payout.created' : 'lease_payout.recalculated',
                auditable: $payout,
                newValues: [
                    'reference' => $payout->reference,
                    'period_start' => $periodStart,
                    'gross_revenue_minor' => $payout->gross_revenue_minor,
                    'earned_minor' => $payout->earned_minor,
                    'net_payable_minor' => $payout->net_payable_minor,
                    'currency' => $payout->currency,
                    'excluded_hire_count' => $payout->excluded_hire_count,
                ],
                user: $lockedActor,
            );

            return $payout->fresh('lease');
        }, 3);
    }

    /** Records what is being held back, and why. */
    public function applyDeductions(
        User $actor,
        VehicleLeasePayout $payout,
        string $amount,
        ?string $note,
    ): VehicleLeasePayout {
        return DB::transaction(function () use ($actor, $payout, $amount, $note): VehicleLeasePayout {
            $lockedActor = LeasingAccess::lockedManager($actor);

            $locked = VehicleLeasePayout::query()
                ->whereKey($payout->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->status->isEditable()) {
                throw ValidationException::withMessages([
                    'status' => 'This payout has already been approved. Figures stop moving at that point.',
                ]);
            }

            try {
                $minor = Money::parse(trim($amount), $locked->currency);
            } catch (InvalidArgumentException $exception) {
                throw ValidationException::withMessages(['deductions' => $exception->getMessage()]);
            }

            // A deduction bigger than the earnings would make the owner owe
            // PISFA, which this record cannot express — that is a debt, not a
            // payout, and it needs a conversation rather than a negative number.
            if ($minor > $locked->earned_minor) {
                throw ValidationException::withMessages([
                    'deductions' => 'That is more than the owner earned this period. '
                        .'Record the difference separately rather than as a negative payout.',
                ]);
            }

            if ($minor > 0) {
                Validator::make(
                    ['note' => $note === null ? null : trim($note)],
                    ['note' => ['required', 'string', 'min:5', 'max:255']],
                    ['note.required' => 'Say what is being deducted and why — the owner sees this line.'],
                )->validate();
            }

            $previous = $locked->deductions_minor;

            $locked->forceFill([
                'deductions_minor' => $minor,
                'deductions_note' => $minor > 0 ? trim((string) $note) : null,
                'net_payable_minor' => max(0, $locked->earned_minor - $minor),
            ])->save();

            $this->auditLogger->record(
                event: 'lease_payout.deductions_applied',
                auditable: $locked,
                oldValues: ['deductions_minor' => $previous],
                newValues: [
                    'deductions_minor' => $minor,
                    'net_payable_minor' => $locked->net_payable_minor,
                ],
                user: $lockedActor,
            );

            return $locked->fresh('lease');
        }, 3);
    }

    /**
     * The money the vehicle earned in the period, and what that means for the owner.
     *
     * @return array<string, mixed>
     */
    private function basisFor(VehicleLease $lease, string $periodStart, string $periodEnd): array
    {
        $grossMinor = 0;
        $hireCount = 0;
        $excludedCount = 0;

        if ($lease->vehicle_id !== null) {
            $hires = CarHireBooking::query()
                ->where('vehicle_id', $lease->vehicle_id)
                // Only finished hires: money from a booking still running has
                // not been earned yet, and one that was cancelled never will be.
                ->where('status', CarHireBookingStatus::Completed->value)
                // Attributed to the month the vehicle came back, so a hire that
                // straddles a month boundary lands in exactly one period.
                ->whereBetween('return_at', [
                    CarbonImmutable::parse($periodStart)->startOfDay(),
                    CarbonImmutable::parse($periodEnd)->endOfDay(),
                ])
                ->get(['id', 'rental_subtotal_minor', 'currency']);

            foreach ($hires as $hire) {
                if (strtoupper((string) $hire->currency) !== strtoupper($lease->currency)) {
                    // Counted, never converted. The console shows the number so
                    // the desk knows to settle it by hand.
                    $excludedCount++;

                    continue;
                }

                // The deposit is the customer's money held and returned, so the
                // subtotal is the revenue, not the total.
                $grossMinor += (int) $hire->rental_subtotal_minor;
                $hireCount++;
            }
        }

        $earnedMinor = match ($lease->payout_model) {
            LeasePayoutModel::FixedMonthly => (int) ($lease->monthly_retainer_minor ?? 0),
            LeasePayoutModel::RevenueShare => $this->share($grossMinor, (int) ($lease->revenue_share_bps ?? 0)),
        };

        return [
            'gross_revenue_minor' => $grossMinor,
            'hire_count' => $hireCount,
            'excluded_hire_count' => $excludedCount,
            'revenue_share_bps' => $lease->payout_model->usesShare() ? $lease->revenue_share_bps : null,
            'earned_minor' => $earnedMinor,
            // Deductions are applied separately and deliberately, so a fresh
            // calculation starts from nothing held back.
            'deductions_minor' => 0,
            'deductions_note' => null,
            'net_payable_minor' => $earnedMinor,
        ];
    }

    /**
     * Basis points of an integer amount, rounded half up, with the division last.
     *
     * intdiv() truncates, which would quietly shave a shilling off every month
     * in the owner's disfavour; adding half the divisor first rounds instead.
     */
    private function share(int $grossMinor, int $bps): int
    {
        if ($grossMinor < 1 || $bps < 1) {
            return 0;
        }

        if ($grossMinor > intdiv(PHP_INT_MAX, $bps)) {
            throw ValidationException::withMessages([
                'gross_revenue_minor' => 'That period earned more than this calculation can represent.',
            ]);
        }

        return intdiv($grossMinor * $bps + 5000, 10000);
    }
}
