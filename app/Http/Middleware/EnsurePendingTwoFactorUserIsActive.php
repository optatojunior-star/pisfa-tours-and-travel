<?php

namespace App\Http\Middleware;

use App\Actions\Fortify\RedirectIfTwoFactorAuthenticatable;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePendingTwoFactorUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->routeIs('two-factor.login', 'two-factor.login.store')) {
            return $next($request);
        }

        $userId = $request->session()->get('login.id');

        if ($userId === null) {
            return $next($request);
        }

        $user = User::query()->find($userId);
        $expiresAt = (int) $request->session()->get(
            RedirectIfTwoFactorAuthenticatable::PENDING_EXPIRES_AT_KEY,
            0,
        );
        $expectedStateHash = (string) $request->session()->get(
            RedirectIfTwoFactorAuthenticatable::PENDING_STATE_HASH_KEY,
            '',
        );

        if ($user instanceof User &&
            $user->isActive() &&
            $user->hasEnabledTwoFactorAuthentication() &&
            $expiresAt >= now()->getTimestamp() &&
            $expectedStateHash !== '' &&
            hash_equals(RedirectIfTwoFactorAuthenticatable::stateHash($user), $expectedStateHash)) {
            return $next($request);
        }

        $request->session()->forget([
            'login.id',
            'login.remember',
            RedirectIfTwoFactorAuthenticatable::PENDING_EXPIRES_AT_KEY,
            RedirectIfTwoFactorAuthenticatable::PENDING_STATE_HASH_KEY,
        ]);
        $request->session()->regenerate();
        $request->session()->regenerateToken();

        if ($request->expectsJson()) {
            return response()->json(['message' => trans('auth.failed')], 422);
        }

        return redirect()->route('login')->withErrors([
            'email' => trans('auth.failed'),
        ]);
    }
}
