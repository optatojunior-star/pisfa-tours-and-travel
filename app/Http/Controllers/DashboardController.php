<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\Dashboard\CustomerSnapshot;
use App\Services\Dashboard\OperationsSnapshot;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The one /dashboard entry point, dispatching by role.
 *
 * Each role gets a view built from its own data, rather than one screen with
 * everything on it hidden behind conditionals — that pattern is how a figure
 * meant for staff ends up rendered for a customer.
 */
class DashboardController extends Controller
{
    public function __invoke(
        Request $request,
        OperationsSnapshot $operations,
        CustomerSnapshot $customers,
    ): View|RedirectResponse {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(403);
        }

        if ($user->canAccessAdministration()) {
            return view('admin.dashboard', ['snapshot' => $operations->forDate()]);
        }

        if ($user->hasRole(UserRole::Customer)) {
            // The F13 portal is the customer's home; /dashboard is the shared
            // entry point that hands them to it.
            return redirect()->route('portal.index');
        }

        if ($user->hasRole(UserRole::Driver)) {
            return redirect()->route('drivers.index');
        }

        // Any other role has no screen of its own. Saying so is more honest
        // than showing an empty console.
        return view('dashboard.pending', ['user' => $user]);
    }
}
