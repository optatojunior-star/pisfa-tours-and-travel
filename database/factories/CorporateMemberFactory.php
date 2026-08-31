<?php

namespace Database\Factories;

use App\Enums\CorporateMemberRole;
use App\Models\CorporateAccount;
use App\Models\CorporateMember;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CorporateMember> */
class CorporateMemberFactory extends Factory
{
    public function definition(): array
    {
        return [
            'corporate_account_id' => CorporateAccount::factory(),
            'user_id' => User::factory(),
            'role' => CorporateMemberRole::Booker,
            'job_title' => 'Operations officer',
            'is_active' => true,
        ];
    }

    public function on(CorporateAccount $account): static
    {
        return $this->state(fn (): array => ['corporate_account_id' => $account->getKey()]);
    }

    public function forUser(User $user): static
    {
        return $this->state(fn (): array => ['user_id' => $user->getKey()]);
    }

    public function role(CorporateMemberRole $role): static
    {
        return $this->state(fn (): array => ['role' => $role]);
    }

    public function deactivated(): static
    {
        return $this->state(fn (): array => [
            'is_active' => false,
            'deactivated_at' => now()->subDay(),
        ]);
    }
}
