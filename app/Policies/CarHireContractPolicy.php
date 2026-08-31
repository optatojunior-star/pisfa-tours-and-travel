<?php

namespace App\Policies;

use App\Models\CarHireContract;
use App\Models\User;

class CarHireContractPolicy
{
    public function view(User $user, CarHireContract $contract): bool
    {
        return $user->can('downloadContract', $contract->booking);
    }

    public function accept(User $user, CarHireContract $contract): bool
    {
        return $user->can('acceptContract', $contract->booking);
    }
}
