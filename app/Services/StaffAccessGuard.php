<?php

namespace App\Services;

use App\Enums\StaffRoles;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class StaffAccessGuard
{
    public function assertMayDeleteOwnAccount(User $user): void
    {
        if (StaffRoles::isManageable($user->role)) {
            throw ValidationException::withMessages([
                'password' => 'Team accounts cannot be self-deleted. Ask a super administrator to manage access instead.',
            ])->errorBag('userDeletion');
        }
    }
}
