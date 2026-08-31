<?php

namespace App\Actions\Loyalty;

use App\Enums\AccountStatus;
use App\Enums\LoyaltyTransactionType;
use App\Enums\UserRole;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyTransaction;
use App\Models\User;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Redeems points for account credit.
 *
 * Every rule is enforced server-side under a row lock. The browser supplies
 * only a points quantity and an idempotency key; the minimum, the balance, and
 * the monetary value all come from configuration and the locked account.
 */
class RedeemLoyaltyPoints
{
    public function __construct(private readonly RecordLoyaltyMovement $ledger) {}

    public function execute(
        User $actor,
        int $points,
        string $idempotencyKey,
    ): LoyaltyTransaction {
        Validator::make(
            ['points' => $points, 'idempotency_key' => trim($idempotencyKey)],
            [
                'points' => ['required', 'integer', 'min:1'],
                'idempotency_key' => ['required', 'uuid'],
            ],
        )->validate();

        if ($actor->status !== AccountStatus::Active || ! $actor->hasRole(UserRole::Customer)) {
            throw new AuthorizationException;
        }

        $minimum = (int) config('loyalty.redemption.minimum_points', 500);

        if ($points < $minimum) {
            throw ValidationException::withMessages([
                'points' => 'The minimum redemption is '.number_format($minimum).' points.',
            ]);
        }

        return DB::transaction(function () use ($actor, $points, $idempotencyKey, $minimum): LoyaltyTransaction {
            $account = LoyaltyAccount::query()
                ->where('user_id', $actor->getKey())
                ->lockForUpdate()
                ->first();

            if ($account === null) {
                throw ValidationException::withMessages([
                    'points' => 'You have no loyalty points to redeem yet.',
                ]);
            }

            // Re-checked inside the lock, so two concurrent redemptions cannot
            // together spend more than the balance holds.
            if ($account->points_balance < $points) {
                throw ValidationException::withMessages([
                    'points' => 'You only have '.number_format($account->points_balance).' points available.',
                ]);
            }

            if ($account->points_balance < $minimum) {
                throw ValidationException::withMessages([
                    'points' => 'You need at least '.number_format($minimum).' points to redeem.',
                ]);
            }

            $valueMinor = LoyaltyAccount::pointsValueMinor($points);
            $currency = (string) config('payments.base_currency', 'UGX');

            $transaction = $this->ledger->execute(
                account: $account,
                type: LoyaltyTransactionType::Redeemed,
                points: $points,
                description: 'Redeemed '.number_format($points).' points for '
                    .Money::format($valueMinor, $currency).' of account credit.',
                idempotencyKey: $idempotencyKey,
                payload: ['value_minor' => $valueMinor, 'currency' => $currency],
                actor: $actor,
            );

            if ($transaction === null) {
                // The same key was already used. Return the original rather
                // than redeeming a second time.
                $existing = LoyaltyTransaction::query()
                    ->where('loyalty_account_id', $account->getKey())
                    ->where('idempotency_key', trim($idempotencyKey))
                    ->first();

                if ($existing === null) {
                    throw ValidationException::withMessages([
                        'points' => 'That redemption could not be completed. Please try again.',
                    ]);
                }

                return $existing;
            }

            return $transaction;
        }, 3);
    }
}
