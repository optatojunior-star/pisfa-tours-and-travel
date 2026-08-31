<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Laravel\Fortify\Fortify;

class TwoFactorSecurityController extends Controller
{
    public function __invoke(Request $request): View
    {
        /** @var User $user */
        $user = $request->user()->fresh();
        $hasSecret = filled($user->two_factor_secret);
        $enabled = $user->hasEnabledTwoFactorAuthentication();

        return view('profile.security', [
            'user' => $user,
            'enabled' => $enabled,
            'pendingConfirmation' => $hasSecret && ! $enabled,
            'qrCodeSvg' => $hasSecret && ! $enabled ? $user->twoFactorQrCodeSvg() : null,
            'setupKey' => $hasSecret && ! $enabled
                ? Fortify::currentEncrypter()->decrypt($user->two_factor_secret)
                : null,
            'recoveryCodes' => $enabled ? $user->recoveryCodes() : [],
            'required' => $user->requiresTwoFactorAuthentication(),
        ]);
    }
}
