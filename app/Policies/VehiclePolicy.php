<?php

namespace App\Policies;

use App\Actions\Fleet\FleetAccess;
use App\Models\User;
use App\Models\Vehicle;

class VehiclePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAccessAdministration();
    }

    public function view(User $user, Vehicle $vehicle): bool
    {
        return $user->canAccessAdministration();
    }

    public function create(User $user): bool
    {
        return $user->canAccessAdministration();
    }

    public function update(User $user, Vehicle $vehicle): bool
    {
        return $user->canAccessAdministration();
    }

    /** Recording maintenance, fuel, and odometer readings. */
    public function manageFleet(User $user, Vehicle $vehicle): bool
    {
        return FleetAccess::canManage($user);
    }

    /**
     * Taking a vehicle out of the fleet permanently. Distinct from ordinary
     * editing because it removes an asset from every future report.
     */
    public function retire(User $user, Vehicle $vehicle): bool
    {
        return FleetAccess::canRetire($user);
    }
}
