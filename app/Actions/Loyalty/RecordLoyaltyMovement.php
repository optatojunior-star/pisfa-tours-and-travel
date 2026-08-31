<?php

namespace App\Actions\Loyalty;

use App\Enums\LoyaltyTier;
use App\Enums\LoyaltyTransactionType;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyTransaction;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The single writer for the points ledger.
 *
 * Everything that moves points goes through here — earning, referrals,
 * redemption, expiry, manual adjustment — so the balance, the lifetime total,
 * the tier, and the activity clock can never drift out of step with the ledger.
 *
 * Duplicate prevention is the unique (account, idempotency_key) index, not an
 * application check: two concurrent awards for the same booking collide in the
 * database rather than both succeeding.
 */
class RecordLoyaltyMovement
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @param  int  $points  Magnitude; the sign is derived from the type.
     * @param  array<string, mixed>  $payload
     * @return LoyaltyTransaction|null Null when the movement was already recorded.
     */
    public function execute(
        LoyaltyAccount $account,
        LoyaltyTransactionType $type,
        int $points,
        string $description,
        string $idempotencyKey,
        ?Model $source = null,
        array $payload = [],
        ?User $actor = null,
        bool $allowNegativeAdjustment = false,
    ): ?LoyaltyTransaction {
        if ($points < 1) {
            throw ValidationException::withMessages([
                'points' => 'A loyalty movement must involve at least one point.',
            ]);
        }

        return DB::transaction(function () use (
            $account, $type, $points, $description, $idempotencyKey,
            $source, $payload, $actor, $allowNegativeAdjustment
        ): ?LoyaltyTransaction {
            $locked = LoyaltyAccount::query()
                ->whereKey($account->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $signed = $this->signedPoints($type, $points, $allowNegativeAdjustment);
            $newBalance = $locked->points_balance + $signed;

            if ($newBalance < 0) {
                throw ValidationException::withMessages([
                    'points' => 'This would take the balance below zero.',
                ]);
            }

            // Only credits raise lifetime standing, so redeeming or expiring
            // can never demote a customer who already earned their tier.
            $lifetime = $locked->lifetime_points
                + ($type->countsTowardsLifetime() ? $points : 0);
            $tier = LoyaltyTier::forLifetimePoints($lifetime);

            try {
                $transaction = LoyaltyTransaction::query()->create([
                    'loyalty_account_id' => $locked->getKey(),
                    'type' => $type,
                    'points' => $signed,
                    'balance_after' => $newBalance,
                    'source_type' => $source?->getMorphClass(),
                    'source_id' => $source?->getKey(),
                    'description' => $description,
                    'payload' => $payload === [] ? null : $payload,
                    'actor_user_id' => $actor?->getKey(),
                    'idempotency_key' => $idempotencyKey,
                ]);
            } catch (QueryException) {
                // Unique (account, idempotency_key) violated: this exact
                // movement is already recorded. Returning null lets callers
                // treat a replay as a no-op rather than an error.
                return null;
            }

            $previousTier = $locked->tier;

            $locked->forceFill([
                'points_balance' => $newBalance,
                'lifetime_points' => $lifetime,
                'tier' => $tier,
                'last_activity_at' => $type->refreshesActivity()
                    ? now()
                    : $locked->last_activity_at,
            ])->save();

            $this->auditLogger->record(
                event: 'loyalty.'.$type->value,
                auditable: $transaction,
                oldValues: [
                    'points_balance' => $locked->getOriginal('points_balance'),
                    'tier' => $previousTier->value,
                ],
                newValues: [
                    'points' => $signed,
                    'points_balance' => $newBalance,
                    'lifetime_points' => $lifetime,
                    'tier' => $tier->value,
                ],
                user: $actor,
            );

            return $transaction;
        }, 3);
    }

    private function signedPoints(
        LoyaltyTransactionType $type,
        int $points,
        bool $allowNegativeAdjustment,
    ): int {
        if ($type->isDebit()) {
            return -$points;
        }

        if ($type === LoyaltyTransactionType::Adjustment) {
            return $allowNegativeAdjustment ? -$points : $points;
        }

        return $points;
    }
}
