<?php

namespace App\Actions\Leasing;

use App\Enums\AccountStatus;
use App\Enums\LeaseApplicationStatus;
use App\Enums\LeasePayoutModel;
use App\Enums\LeaseStatus;
use App\Enums\UserRole;
use App\Models\User;
use App\Models\VehicleLease;
use App\Models\VehicleLeaseApplication;
use App\Services\AuditLogger;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Draws up the terms of a lease.
 *
 * Manager-only, and editable only while the agreement is a draft: once it is
 * active the owner has been told what they will be paid, and payouts have been
 * computed against these numbers. Changing them afterwards would rewrite what
 * was agreed, so a renegotiation is a new agreement.
 */
class SaveVehicleLease
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /** @param array<string, mixed> $attributes */
    public function create(
        User $actor,
        User $owner,
        array $attributes,
        ?VehicleLeaseApplication $application = null,
    ): VehicleLease {
        $input = $this->validated($attributes);

        return DB::transaction(function () use ($actor, $owner, $input, $application): VehicleLease {
            $lockedActor = LeasingAccess::lockedCommitter($actor);

            $lockedOwner = User::query()
                ->whereKey($owner->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            // Payouts are addressed to an account, so it has to be one that can
            // actually receive them.
            if ($lockedOwner->status !== AccountStatus::Active
                || ! $lockedOwner->hasRole(UserRole::Customer)) {
                throw ValidationException::withMessages([
                    'owner_id' => 'The owner needs an active customer account before a lease can be drawn up.',
                ]);
            }

            $lockedApplication = null;

            if ($application !== null) {
                $lockedApplication = VehicleLeaseApplication::query()
                    ->whereKey($application->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                $this->assertApplicationIsAvailable($lockedApplication);
            }

            $lease = new VehicleLease;
            $lease->forceFill(array_merge($input, [
                'reference' => 'VL-'.Str::upper((string) Str::ulid()),
                'owner_id' => $lockedOwner->getKey(),
                'application_id' => $lockedApplication?->getKey(),
                'status' => LeaseStatus::Draft,
                'created_by_user_id' => $lockedActor->getKey(),
            ]))->save();

            $this->auditLogger->record(
                event: 'vehicle_lease.created',
                auditable: $lease,
                newValues: [
                    'reference' => $lease->reference,
                    'owner_id' => $lockedOwner->getKey(),
                    'payout_model' => $lease->payout_model->value,
                    'monthly_retainer_minor' => $lease->monthly_retainer_minor,
                    'revenue_share_bps' => $lease->revenue_share_bps,
                    'currency' => $lease->currency,
                    'starts_on' => $lease->starts_on->toDateString(),
                ],
                user: $lockedActor,
            );

            return $lease->fresh(['owner', 'application']);
        }, 3);
    }

    /** @param array<string, mixed> $attributes */
    public function update(User $actor, VehicleLease $lease, array $attributes): VehicleLease
    {
        $input = $this->validated($attributes);

        return DB::transaction(function () use ($actor, $lease, $input): VehicleLease {
            $lockedActor = LeasingAccess::lockedCommitter($actor);

            $locked = VehicleLease::query()
                ->whereKey($lease->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->status->isEditable()) {
                throw ValidationException::withMessages([
                    'status' => 'Terms can only change while the agreement is a draft. '
                        .'A renegotiation is a new lease, so the record of what was agreed stays intact.',
                ]);
            }

            $previous = [
                'payout_model' => $locked->payout_model->value,
                'monthly_retainer_minor' => $locked->monthly_retainer_minor,
                'revenue_share_bps' => $locked->revenue_share_bps,
                'currency' => $locked->currency,
            ];

            $locked->forceFill($input)->save();

            $this->auditLogger->record(
                event: 'vehicle_lease.updated',
                auditable: $locked,
                oldValues: $previous,
                newValues: [
                    'payout_model' => $locked->payout_model->value,
                    'monthly_retainer_minor' => $locked->monthly_retainer_minor,
                    'revenue_share_bps' => $locked->revenue_share_bps,
                    'currency' => $locked->currency,
                ],
                user: $lockedActor,
            );

            return $locked->fresh(['owner', 'application']);
        }, 3);
    }

    /**
     * An approved application may back exactly one lease.
     *
     * Two agreements from one offer would mean two sets of terms for the same
     * car, and a payout run would have to guess which one the owner is on.
     */
    private function assertApplicationIsAvailable(VehicleLeaseApplication $application): void
    {
        if ($application->status !== LeaseApplicationStatus::Approved) {
            throw ValidationException::withMessages([
                'application_id' => 'Only an approved application can become a lease. '
                    .'Inspect the vehicle and approve it first.',
            ]);
        }

        $taken = VehicleLease::query()
            ->where('application_id', $application->getKey())
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([
                'application_id' => 'This application already has a lease.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function validated(array $attributes): array
    {
        $validated = Validator::make($attributes, [
            'payout_model' => ['required', Rule::enum(LeasePayoutModel::class)],
            'monthly_retainer' => ['nullable', 'string', 'max:24'],
            // Basis points, so a percentage is an integer and never a float.
            // 10000 bps is the whole of the revenue; more would be paying out
            // more than the vehicle earned.
            'revenue_share_bps' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'currency' => ['required', Rule::in(config('pisfa.currency.supported', ['UGX', 'USD']))],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['nullable', 'date', 'after:starts_on'],
            'notice_period_days' => ['required', 'integer', 'min:0', 'max:365'],
            'terms' => ['nullable', 'string', 'max:8000'],
            'internal_notes' => ['nullable', 'string', 'max:5000'],
        ])->validate();

        $model = LeasePayoutModel::from((string) $validated['payout_model']);
        $currency = strtoupper((string) $validated['currency']);

        $retainerMinor = null;
        $shareBps = null;

        if ($model->usesRetainer()) {
            if (blank($validated['monthly_retainer'] ?? null)) {
                throw ValidationException::withMessages([
                    'monthly_retainer' => 'A fixed retainer needs an amount.',
                ]);
            }

            try {
                $retainerMinor = Money::parse((string) $validated['monthly_retainer'], $currency);
            } catch (InvalidArgumentException $exception) {
                throw ValidationException::withMessages(['monthly_retainer' => $exception->getMessage()]);
            }

            if ($retainerMinor < 1) {
                throw ValidationException::withMessages([
                    'monthly_retainer' => 'A retainer of nothing is not an agreement.',
                ]);
            }
        }

        if ($model->usesShare()) {
            if (blank($validated['revenue_share_bps'] ?? null)) {
                throw ValidationException::withMessages([
                    'revenue_share_bps' => 'A revenue share needs a percentage.',
                ]);
            }

            $shareBps = (int) $validated['revenue_share_bps'];
        }

        return [
            'payout_model' => $model,
            'monthly_retainer_minor' => $retainerMinor,
            'revenue_share_bps' => $shareBps,
            'currency' => $currency,
            'starts_on' => CarbonImmutable::parse((string) $validated['starts_on'])->toDateString(),
            'ends_on' => filled($validated['ends_on'] ?? null)
                ? CarbonImmutable::parse((string) $validated['ends_on'])->toDateString()
                : null,
            'notice_period_days' => (int) $validated['notice_period_days'],
            'terms' => filled($validated['terms'] ?? null) ? trim((string) $validated['terms']) : null,
            'internal_notes' => filled($validated['internal_notes'] ?? null)
                ? trim((string) $validated['internal_notes'])
                : null,
        ];
    }
}
