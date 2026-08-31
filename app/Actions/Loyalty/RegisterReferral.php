<?php

namespace App\Actions\Loyalty;

use App\Enums\LoyaltyTransactionType;
use App\Enums\ReferralStatus;
use App\Models\LoyaltyAccount;
use App\Models\Referral;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Attaches a newly registered customer to a referral code.
 *
 * The joining customer's welcome bonus is paid immediately; the referrer is
 * paid only once the referred customer completes a first eligible booking, so
 * codes cannot be farmed with throwaway sign-ups.
 */
class RegisterReferral
{
    public function __construct(private readonly RecordLoyaltyMovement $ledger) {}

    public function execute(User $referred, ?string $code): ?Referral
    {
        $code = strtoupper(trim((string) $code));

        if ($code === '') {
            return null;
        }

        return DB::transaction(function () use ($referred, $code): ?Referral {
            $referrerAccount = LoyaltyAccount::query()
                ->where('referral_code', $code)
                ->lockForUpdate()
                ->first();

            // An unknown code is ignored rather than rejected: a mistyped code
            // must never block someone from registering.
            if ($referrerAccount === null) {
                return null;
            }

            // Self-referral earns nothing.
            if ($referrerAccount->user_id === $referred->getKey()) {
                return null;
            }

            $existing = Referral::query()
                ->where('referred_user_id', $referred->getKey())
                ->lockForUpdate()
                ->first();

            // A person can be referred only once, ever.
            if ($existing !== null) {
                return $existing;
            }

            $referral = Referral::query()->create([
                'referrer_account_id' => $referrerAccount->getKey(),
                'referred_user_id' => $referred->getKey(),
                'code_used' => $code,
                'status' => ReferralStatus::Pending,
            ]);

            $joinPoints = (int) config('loyalty.referral.join_points', 100);

            if ($joinPoints > 0) {
                $this->ledger->execute(
                    account: LoyaltyAccount::forUser($referred),
                    type: LoyaltyTransactionType::ReferralJoin,
                    points: $joinPoints,
                    description: 'Welcome bonus for joining with a referral code.',
                    idempotencyKey: 'referral-join:'.$referral->getKey(),
                    source: $referral,
                );
            }

            return $referral;
        }, 3);
    }
}
