<?php

namespace App\Actions\Fortify;

use App\Models\User;
use BackedEnum;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable as FortifyRedirectIfTwoFactorAuthenticatable;

class RedirectIfTwoFactorAuthenticatable extends FortifyRedirectIfTwoFactorAuthenticatable
{
    public const PENDING_EXPIRES_AT_KEY = 'login.expires_at';

    public const PENDING_STATE_HASH_KEY = 'login.state_hash';

    protected function twoFactorChallengeResponse($request, $user)
    {
        $request->session()->regenerate();
        $request->session()->put([
            self::PENDING_EXPIRES_AT_KEY => now()
                ->addSeconds(config('security.two_factor.challenge_ttl_seconds', 300))
                ->getTimestamp(),
            self::PENDING_STATE_HASH_KEY => $user instanceof User
                ? self::stateHash($user)
                : null,
        ]);

        if ($user instanceof User && $user->requiresTwoFactorAuthentication()) {
            $request->merge(['remember' => false]);
        }

        return parent::twoFactorChallengeResponse($request, $user);
    }

    public static function stateHash(User $user): string
    {
        $status = $user->status instanceof BackedEnum
            ? $user->status->value
            : (string) $user->status;

        return hash_hmac('sha256', implode('|', [
            $user->getAuthIdentifier(),
            $user->getAuthPassword(),
            $status,
            (string) $user->two_factor_secret,
            (string) $user->two_factor_confirmed_at?->getTimestamp(),
        ]), (string) config('app.key'));
    }
}
