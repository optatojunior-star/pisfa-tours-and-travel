<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureTwoFactorAuthenticationIsConfigured
{
    public const VERIFIED_AT_SESSION_KEY = 'auth.two_factor_verified_at';

    public const VERIFIED_USER_SESSION_KEY = 'auth.two_factor_verified_user_id';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->requiresTwoFactorAuthentication()) {
            return $next($request);
        }

        if (! $user->hasEnabledTwoFactorAuthentication()) {
            return redirect()->route('profile.security')->with(
                'warning',
                'Set up two-factor authentication before continuing.',
            );
        }

        if ((int) $request->session()->get(self::VERIFIED_USER_SESSION_KEY) === (int) $user->getKey() &&
            is_int($request->session()->get(self::VERIFIED_AT_SESSION_KEY))) {
            return $next($request);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->withErrors([
            'email' => 'Please sign in again and complete two-factor authentication.',
        ]);
    }
}
