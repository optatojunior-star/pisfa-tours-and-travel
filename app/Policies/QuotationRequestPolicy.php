<?php

namespace App\Policies;

use App\Actions\Billing\BillingAccess;
use App\Enums\UserRole;
use App\Models\QuotationRequest;
use App\Models\User;

class QuotationRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return BillingAccess::canManage($user);
    }

    public function view(User $user, QuotationRequest $request): bool
    {
        return BillingAccess::canManage($user) || $this->owns($user, $request);
    }

    public function manage(User $user, QuotationRequest $request): bool
    {
        return BillingAccess::canManage($user);
    }

    private function owns(User $user, QuotationRequest $request): bool
    {
        return $user->isActive()
            && $user->hasRole(UserRole::Customer)
            && $request->customer_id !== null
            && $request->customer_id === $user->getKey();
    }
}
