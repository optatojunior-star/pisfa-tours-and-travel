<?php

namespace Database\Seeders;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DemoRoleUserSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn('Demo role users were not seeded outside local/testing.');

            return;
        }

        $password = app()->environment('testing') ? 'password' : Str::password(24);
        $createdAny = false;

        foreach (UserRole::cases() as $role) {
            $user = User::query()->firstOrCreate(
                ['email' => str_replace('_', '.', $role->value).'@pisfa.test'],
                [
                    'name' => $role->label().' Demo',
                    'password' => $password,
                    'role' => $role,
                    'status' => AccountStatus::Active,
                    'preferred_language' => 'en',
                    'preferred_currency' => 'UGX',
                    'email_verified_at' => now(),
                ],
            );

            $createdAny = $createdAny || $user->wasRecentlyCreated;
        }

        if ($createdAny && ! app()->environment('testing')) {
            $this->command?->info("Local demo users created with password: {$password}");
            $this->command?->warn('Store this password securely; rerunning the seeder will not replace existing passwords.');
        }
    }
}
