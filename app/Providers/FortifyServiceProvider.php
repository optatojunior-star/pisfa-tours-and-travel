<?php

namespace App\Providers;

use App\Actions\Fortify\RedirectIfTwoFactorAuthenticatable;
use App\Enums\AccountStatus;
use App\Http\Middleware\AddTwoFactorSecurityHeaders;
use App\Http\Middleware\EnsurePendingTwoFactorUserIsActive;
use App\Http\Middleware\PreventRequiredTwoFactorDisabling;
use App\Http\Middleware\PreventUnsafeTwoFactorSecretRotation;
use App\Listeners\RecordTwoFactorSecurityEvent;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Events\RecoveryCodeReplaced;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationChallenged;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;
use Laravel\Fortify\Events\ValidTwoFactorAuthenticationCodeProvided;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Run outside StartSession so its cache limiter cannot overwrite
        // no-store headers on pages that reveal two-factor secrets.
        $this->app['router']->prependMiddlewareToGroup('web', AddTwoFactorSecurityHeaders::class);

        Fortify::loginView(fn () => view('auth.login'));
        Fortify::confirmPasswordView(fn () => view('auth.confirm-password'));
        Fortify::twoFactorChallengeView(fn () => view('auth.two-factor-challenge'));

        Fortify::redirectUserForTwoFactorAuthenticationUsing(RedirectIfTwoFactorAuthenticatable::class);

        Fortify::authenticateUsing(function (Request $request): ?User {
            $request->session()->forget([
                'login.id',
                'login.remember',
                RedirectIfTwoFactorAuthenticatable::PENDING_EXPIRES_AT_KEY,
                RedirectIfTwoFactorAuthenticatable::PENDING_STATE_HASH_KEY,
            ]);

            $user = User::query()
                ->where('email', Str::lower((string) $request->input('email')))
                ->first();

            if ($user === null ||
                $user->status !== AccountStatus::Active ||
                ! Hash::check((string) $request->input('password'), $user->getAuthPassword())) {
                return null;
            }

            if ($user->requiresTwoFactorAuthentication()) {
                $request->merge(['remember' => false]);
            }

            if (Hash::needsRehash($user->getAuthPassword())) {
                $user->forceFill([
                    'password' => Hash::make((string) $request->input('password')),
                ])->save();
            }

            return $user;
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('two-factor', function (Request $request) {
            $pendingUserId = $request->session()->get('login.id');
            $key = $pendingUserId === null
                ? 'ip:'.($request->ip() ?? 'unknown')
                : 'user:'.$pendingUserId;

            return Limit::perMinute(5)->by($key);
        });

        Event::listen([
            TwoFactorAuthenticationChallenged::class,
            TwoFactorAuthenticationEnabled::class,
            TwoFactorAuthenticationConfirmed::class,
            TwoFactorAuthenticationDisabled::class,
            RecoveryCodesGenerated::class,
            RecoveryCodeReplaced::class,
            ValidTwoFactorAuthenticationCodeProvided::class,
            TwoFactorAuthenticationFailed::class,
        ], RecordTwoFactorSecurityEvent::class);

        $this->app->booted(function (): void {
            $routes = app('router')->getRoutes();
            $routes->refreshNameLookups();

            $routes->getByName('two-factor.login')
                ?->middleware([
                    AddTwoFactorSecurityHeaders::class,
                    EnsurePendingTwoFactorUserIsActive::class,
                ]);
            $routes->getByName('two-factor.login.store')
                ?->middleware([
                    AddTwoFactorSecurityHeaders::class,
                    EnsurePendingTwoFactorUserIsActive::class,
                ]);
            $routes->getByName('two-factor.disable')
                ?->middleware(PreventRequiredTwoFactorDisabling::class);
            $routes->getByName('two-factor.enable')
                ?->middleware(PreventUnsafeTwoFactorSecretRotation::class);

            foreach ([
                'two-factor.enable',
                'two-factor.confirm',
                'two-factor.disable',
                'two-factor.qr-code',
                'two-factor.secret-key',
                'two-factor.recovery-codes',
                'two-factor.regenerate-recovery-codes',
            ] as $routeName) {
                $routes->getByName($routeName)
                    ?->middleware(AddTwoFactorSecurityHeaders::class);
            }
        });
    }
}
