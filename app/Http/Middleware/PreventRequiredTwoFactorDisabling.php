<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PreventRequiredTwoFactorDisabling
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($request->routeIs('two-factor.disable') &&
            $user instanceof User &&
            $user->requiresTwoFactorAuthentication()) {
            abort(Response::HTTP_FORBIDDEN, 'Two-factor authentication is required for this account.');
        }

        return $next($request);
    }
}
