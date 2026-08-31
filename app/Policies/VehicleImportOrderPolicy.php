<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;
use App\Models\VehicleImportOrder;

class VehicleImportOrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAccessAdministration();
    }

    public function view(User $user, VehicleImportOrder $order): bool
    {
        return $user->canAccessAdministration() || $this->owns($user, $order);
    }

    /**
     * Documents attached to an import inherit this through DocumentPolicy, so a
     * customer reaches their own shipping paperwork and nobody else's.
     */
    public function update(User $user, VehicleImportOrder $order): bool
    {
        return $user->canAccessAdministration();
    }

    public function quote(User $user, VehicleImportOrder $order): bool
    {
        return $user->canAccessAdministration();
    }

    public function transition(User $user, VehicleImportOrder $order): bool
    {
        return $user->canAccessAdministration();
    }

    /** Either side may post to the shared thread; only staff may post internally. */
    public function message(User $user, VehicleImportOrder $order): bool
    {
        return $user->canAccessAdministration() || $this->owns($user, $order);
    }

    /**
     * A customer may withdraw only before sourcing has cost PISFA money. Once
     * the deposit is paid, cancelling becomes an operations decision.
     */
    public function cancel(User $user, VehicleImportOrder $order): bool
    {
        if ($user->canAccessAdministration()) {
            return true;
        }

        return $this->owns($user, $order) && $order->status->customerMayCancel();
    }

    private function owns(User $user, VehicleImportOrder $order): bool
    {
        return $user->isActive()
            && $user->hasRole(UserRole::Customer)
            && $order->customer_id === $user->getKey();
    }
}
