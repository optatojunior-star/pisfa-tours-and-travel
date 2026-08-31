<?php

namespace App\Policies;

use App\Actions\Billing\BillingAccess;
use App\Enums\InvoiceStatus;
use App\Enums\UserRole;
use App\Models\Invoice;
use App\Models\User;

class InvoicePolicy
{
    public function viewAny(User $user): bool
    {
        return BillingAccess::canManage($user);
    }

    public function view(User $user, Invoice $invoice): bool
    {
        if (BillingAccess::canManage($user)) {
            return true;
        }

        return $invoice->status->isVisibleToCustomer() && $this->owns($user, $invoice);
    }

    public function create(User $user): bool
    {
        return BillingAccess::canManage($user);
    }

    public function issue(User $user, Invoice $invoice): bool
    {
        return BillingAccess::canManage($user)
            && $invoice->canTransitionTo(InvoiceStatus::Issued);
    }

    public function cancel(User $user, Invoice $invoice): bool
    {
        return BillingAccess::canManage($user)
            && $invoice->canTransitionTo(InvoiceStatus::Cancelled);
    }

    /** Voiding touches money already recognised, so managers and above only. */
    public function void(User $user, Invoice $invoice): bool
    {
        return BillingAccess::canWriteOff($user)
            && $invoice->canTransitionTo(InvoiceStatus::Void);
    }

    /** The customer may pay their own issued invoice. */
    public function pay(User $user, Invoice $invoice): bool
    {
        return $this->owns($user, $invoice) && $invoice->acceptsPayment();
    }

    private function owns(User $user, Invoice $invoice): bool
    {
        return $user->isActive()
            && $user->hasRole(UserRole::Customer)
            && $invoice->customer_id !== null
            && $invoice->customer_id === $user->getKey();
    }
}
