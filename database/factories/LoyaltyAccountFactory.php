<?php

namespace Database\Factories;

use App\Enums\LoyaltyTier;
use App\Models\LoyaltyAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LoyaltyAccount> */
class LoyaltyAccountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'points_balance' => 0,
            'lifetime_points' => 0,
            'tier' => LoyaltyTier::Bronze,
            'referral_code' => LoyaltyAccount::generateReferralCode(),
            'last_activity_at' => now(),
        ];
    }

    public function withPoints(int $balance, ?int $lifetime = null): static
    {
        $lifetime ??= $balance;

        return $this->state(fn (): array => [
            'points_balance' => $balance,
            'lifetime_points' => $lifetime,
            'tier' => LoyaltyTier::forLifetimePoints($lifetime),
        ]);
    }

    public function inactiveSince(int $months): static
    {
        return $this->state(fn (): array => [
            'last_activity_at' => now()->subMonths($months),
        ]);
    }
}
