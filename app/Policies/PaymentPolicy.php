<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Payment;
use App\Models\User;

class PaymentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAccessAdministration();
    }

    public function view(User $user, Payment $payment): bool
    {
        return $user->canAccessAdministration() || $this->owns($user, $payment);
    }

    /** Only the payer may drive a checkout to completion. */
    public function pay(User $user, Payment $payment): bool
    {
        return $this->owns($user, $payment) && $payment->status->isInFlight();
    }

    /**
     * Refunds move money out. Staff are deliberately excluded: the brief scopes
     * refunds, reconciliation, and finance to manager and above.
     */
    public function refund(User $user, Payment $payment): bool
    {
        return $user->isActive()
            && $user->hasAnyRole(UserRole::Manager, UserRole::SuperAdmin)
            && $payment->status->isRefundable();
    }

    /** Recording a manual receipt is an operations action against evidence. */
    public function record(User $user): bool
    {
        return $user->canAccessAdministration();
    }

    private function owns(User $user, Payment $payment): bool
    {
        return $user->isActive()
            && $user->hasRole(UserRole::Customer)
            && $payment->customer_id === $user->getKey();
    }
}
