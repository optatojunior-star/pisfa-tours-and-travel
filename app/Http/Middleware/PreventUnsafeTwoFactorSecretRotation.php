<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PreventUnsafeTwoFactorSecretRotation
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($request->routeIs('two-factor.enable') &&
            $request->boolean('force') &&
            $user instanceof User &&
            $user->two_factor_confirmed_at !== null) {
            abort(
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'Disable two-factor authentication before starting a new setup.',
            );
        }

        return $next($request);
    }
}
