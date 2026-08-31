<?php

namespace App\Policies;

use App\Actions\Billing\BillingAccess;
use App\Enums\QuotationStatus;
use App\Enums\UserRole;
use App\Models\Quotation;
use App\Models\User;

class QuotationPolicy
{
    public function viewAny(User $user): bool
    {
        return BillingAccess::canManage($user);
    }

    /**
     * A draft is internal. Anything sent is visible to the customer it is
     * addressed to, and to billing staff.
     */
    public function view(User $user, Quotation $quotation): bool
    {
        if (BillingAccess::canManage($user)) {
            return true;
        }

        return $quotation->status->isVisibleToCustomer() && $this->owns($user, $quotation);
    }

    public function create(User $user): bool
    {
        return BillingAccess::canManage($user);
    }

    /** Pricing may only be edited while the offer is still a draft. */
    public function update(User $user, Quotation $quotation): bool
    {
        return BillingAccess::canManage($user) && $quotation->status->isEditable();
    }

    public function send(User $user, Quotation $quotation): bool
    {
        return BillingAccess::canManage($user)
            && $quotation->canTransitionTo(QuotationStatus::Sent);
    }

    public function revise(User $user, Quotation $quotation): bool
    {
        return BillingAccess::canManage($user)
            && $quotation->canTransitionTo(QuotationStatus::Draft);
    }

    public function cancel(User $user, Quotation $quotation): bool
    {
        return BillingAccess::canManage($user)
            && $quotation->canTransitionTo(QuotationStatus::Cancelled);
    }

    /** Only the customer it was addressed to may accept or decline it. */
    public function respond(User $user, Quotation $quotation): bool
    {
        return $this->owns($user, $quotation) && $quotation->awaitsResponse();
    }

    public function convert(User $user, Quotation $quotation): bool
    {
        return BillingAccess::canManage($user) && $quotation->status->isConvertible();
    }

    private function owns(User $user, Quotation $quotation): bool
    {
        return $user->isActive()
            && $user->hasRole(UserRole::Customer)
            && $quotation->customer_id !== null
            && $quotation->customer_id === $user->getKey();
    }
}
