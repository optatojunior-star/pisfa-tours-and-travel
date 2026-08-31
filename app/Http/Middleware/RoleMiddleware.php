<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    /**
     * Restrict a request to one or more role values.
     *
     * Usage: ->middleware('role:super_admin,manager')
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        abort_unless($user instanceof User, Response::HTTP_UNAUTHORIZED, 'Authentication required.');
        abort_unless($user->isActive(), Response::HTTP_FORBIDDEN, 'Your account is not active.');
        abort_if($roles === [], Response::HTTP_FORBIDDEN, 'No authorized roles were configured.');

        $allowedRoles = array_map(
            static fn (string $role): ?UserRole => UserRole::tryFrom(trim($role)),
            $roles,
        );

        abort_if(
            in_array(null, $allowedRoles, true),
            Response::HTTP_FORBIDDEN,
            'An invalid authorized role was configured.',
        );

        /** @var list<UserRole> $allowedRoles */
        abort_unless(
            $user->hasAnyRole(...$allowedRoles),
            Response::HTTP_FORBIDDEN,
            'You are not authorized to access this resource.',
        );

        return $next($request);
    }
}
