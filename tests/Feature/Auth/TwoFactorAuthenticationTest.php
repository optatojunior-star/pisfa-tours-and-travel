<?php

namespace Tests\Feature\Auth;

use App\Actions\Fortify\RedirectIfTwoFactorAuthenticatable;
use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Http\Middleware\EnsureTwoFactorAuthenticationIsConfigured;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\Http\Controllers\AuthenticatedSessionController;
use Laravel\Fortify\Http\Controllers\ConfirmablePasswordController;
use Laravel\Fortify\Http\Controllers\TwoFactorAuthenticatedSessionController;
use Laravel\Fortify\RecoveryCode;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class TwoFactorAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_fortify_owns_login_logout_password_confirmation_and_challenge_routes(): void
    {
        $routes = Route::getRoutes();

        $this->assertSame(
            AuthenticatedSessionController::class.'@create',
            $routes->getByName('login')->getActionName(),
        );
        $this->assertSame(
            AuthenticatedSessionController::class.'@store',
            $routes->getByName('login.store')->getActionName(),
        );
        $this->assertSame(
            AuthenticatedSessionController::class.'@destroy',
            $routes->getByName('logout')->getActionName(),
        );
        $this->assertSame(
            ConfirmablePasswordController::class.'@show',
            $routes->getByName('password.confirm')->getActionName(),
        );
        $this->assertSame(
            TwoFactorAuthenticatedSessionController::class.'@store',
            $routes->getByName('two-factor.login.store')->getActionName(),
        );
    }

    public function test_only_the_fortify_two_factor_feature_is_enabled_and_passkey_routes_are_absent(): void
    {
        $this->assertTrue(Features::enabled(Features::twoFactorAuthentication()));
        $this->assertFalse(Features::enabled(Features::registration()));
        $this->assertFalse(Features::enabled(Features::resetPasswords()));
        $this->assertFalse(Features::enabled(Features::updateProfileInformation()));
        $this->assertFalse(Features::enabled(Features::updatePasswords()));
        $this->assertFalse(Features::enabled(Features::passkeys()));
        $this->assertFalse(Route::has('passkey.login'));
        $this->assertFalse(Route::has('passkey.store'));

        $this->get('/passkeys/login/options')->assertNotFound();
    }

    public function test_normal_active_users_can_still_log_in_without_a_second_factor(): void
    {
        $user = User::factory()->create();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
        $response->assertSessionMissing('login.id');
    }

    public function test_inactive_accounts_receive_the_same_first_factor_failure_as_bad_credentials(): void
    {
        $user = User::factory()->create(['status' => AccountStatus::Inactive]);

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertSessionHasErrors([
            'email' => trans('auth.failed'),
        ]);
        $this->assertGuest();
    }

    public function test_an_enabled_account_is_sent_to_the_branded_challenge_without_being_authenticated(): void
    {
        $user = User::factory()->create();
        $this->enableTwoFactorFor($user);

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
            'remember' => 'on',
        ]);

        $response->assertRedirect(route('two-factor.login'));
        $response->assertSessionHas('login.id', $user->id);
        $response->assertSessionHas(RedirectIfTwoFactorAuthenticatable::PENDING_STATE_HASH_KEY);
        $response->assertSessionHas(
            RedirectIfTwoFactorAuthenticatable::PENDING_EXPIRES_AT_KEY,
            fn (int $expiresAt): bool => $expiresAt >= now()->addMinutes(4)->getTimestamp(),
        );
        $this->assertGuest();

        $this->get('/two-factor-challenge')
            ->assertOk()
            ->assertSee('Confirm it is really you')
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertHeader('Referrer-Policy', 'no-referrer');
    }

    public function test_a_valid_totp_code_completes_authentication_and_marks_the_session_verified(): void
    {
        $user = User::factory()->create();
        [$secret] = $this->enableTwoFactorFor($user);

        $this->beginTwoFactorLogin($user);

        $response = $this->post('/two-factor-challenge', [
            'code' => $this->currentOtp($secret),
        ]);

        $response->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
        $response->assertSessionHas(
            EnsureTwoFactorAuthenticationIsConfigured::VERIFIED_USER_SESSION_KEY,
            $user->id,
        );
        $response->assertSessionHas(EnsureTwoFactorAuthenticationIsConfigured::VERIFIED_AT_SESSION_KEY);
        $response->assertSessionMissing(RedirectIfTwoFactorAuthenticatable::PENDING_EXPIRES_AT_KEY);
        $response->assertSessionMissing(RedirectIfTwoFactorAuthenticatable::PENDING_STATE_HASH_KEY);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'two_factor.challenge_succeeded',
            'user_id' => $user->id,
        ]);
    }

    public function test_an_invalid_totp_code_is_rejected_and_audited_without_ending_the_pending_challenge(): void
    {
        $user = User::factory()->create();
        $this->enableTwoFactorFor($user);
        $this->beginTwoFactorLogin($user);

        $response = $this->post('/two-factor-challenge', [
            'code' => '000000',
        ]);

        $response->assertRedirect(route('two-factor.login'));
        $response->assertSessionHasErrors('code');
        $response->assertSessionHas('login.id', $user->id);
        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'two_factor.challenge_failed',
            'user_id' => $user->id,
        ]);
    }

    public function test_a_recovery_code_can_only_be_used_once(): void
    {
        $user = User::factory()->create();
        [, $recoveryCodes] = $this->enableTwoFactorFor($user);
        $recoveryCode = $recoveryCodes[0];

        $this->beginTwoFactorLogin($user);

        $this->post('/two-factor-challenge', [
            'recovery_code' => $recoveryCode,
        ])->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
        $this->assertNotContains($recoveryCode, $user->fresh()->recoveryCodes());
        $this->post('/logout')->assertRedirect('/');

        $this->beginTwoFactorLogin($user);

        $response = $this->post('/two-factor-challenge', [
            'recovery_code' => $recoveryCode,
        ]);

        $response->assertSessionHasErrors('recovery_code');
        $this->assertGuest();
    }

    public function test_a_pending_challenge_expires_after_five_minutes_and_is_cleared(): void
    {
        $user = User::factory()->create();
        [$secret] = $this->enableTwoFactorFor($user);
        $this->beginTwoFactorLogin($user);

        $this->travel(301)->seconds();

        $response = $this->post('/two-factor-challenge', [
            'code' => $this->currentOtp($secret),
        ]);

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors([
            'email' => trans('auth.failed'),
        ]);
        $response->assertSessionMissing('login.id');
        $response->assertSessionMissing('login.remember');
        $response->assertSessionMissing(RedirectIfTwoFactorAuthenticatable::PENDING_EXPIRES_AT_KEY);
        $response->assertSessionMissing(RedirectIfTwoFactorAuthenticatable::PENDING_STATE_HASH_KEY);
        $this->assertGuest();
    }

    public function test_deactivation_between_factors_is_rechecked_on_the_challenge_screen(): void
    {
        $user = User::factory()->create();
        $this->enableTwoFactorFor($user);
        $this->beginTwoFactorLogin($user);

        $user->update(['status' => AccountStatus::Suspended]);

        $this->get('/two-factor-challenge')
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email')
            ->assertSessionMissing('login.id');

        $this->assertGuest();
    }

    public function test_deactivation_between_factors_is_rechecked_when_a_code_is_submitted(): void
    {
        $user = User::factory()->create();
        [$secret] = $this->enableTwoFactorFor($user);
        $this->beginTwoFactorLogin($user);

        $user->update(['status' => AccountStatus::Inactive]);

        $this->post('/two-factor-challenge', [
            'code' => $this->currentOtp($secret),
        ])->assertRedirect(route('login'))
            ->assertSessionHasErrors('email')
            ->assertSessionMissing('login.id');

        $this->assertGuest();
    }

    public function test_a_password_or_two_factor_state_change_invalidates_the_pending_challenge(): void
    {
        $user = User::factory()->create();
        $this->enableTwoFactorFor($user);
        $this->beginTwoFactorLogin($user);

        $user->forceFill(['password' => Hash::make('new-password')])->save();

        $this->get('/two-factor-challenge')
            ->assertRedirect(route('login'))
            ->assertSessionMissing('login.id');

        $this->assertGuest();
    }

    public function test_required_roles_cannot_use_remember_me_and_receive_a_verified_session_marker(): void
    {
        $user = User::factory()->create(['role' => UserRole::SuperAdmin]);
        [$secret] = $this->enableTwoFactorFor($user);

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
            'remember' => 'on',
        ]);

        $response->assertSessionHas('login.remember', false);

        $this->post('/two-factor-challenge', [
            'code' => $this->currentOtp($secret),
        ])->assertSessionHas(
            EnsureTwoFactorAuthenticationIsConfigured::VERIFIED_USER_SESSION_KEY,
            $user->id,
        );

        $this->assertAuthenticatedAs($user);
        $this->get(route('admin.staff.index'))->assertOk();
    }

    public function test_required_routes_reject_an_authenticated_session_without_a_second_factor_marker(): void
    {
        $user = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $this->enableTwoFactorFor($user);

        $this->actingAs($user)
            ->get(route('admin.staff.index'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_required_roles_are_sent_to_setup_before_protected_administration(): void
    {
        $user = User::factory()->create(['role' => UserRole::SuperAdmin]);

        $this->actingAs($user)
            ->get(route('admin.staff.index'))
            ->assertRedirect(route('profile.security'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_setup_and_two_factor_endpoints_require_recent_password_confirmation(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('profile.security'))
            ->assertRedirect(route('password.confirm'));

        $this->post(route('two-factor.enable'))
            ->assertRedirect(route('password.confirm'));

        $this->assertNull($user->fresh()->two_factor_secret);
    }

    public function test_setup_confirmation_activates_two_factor_marks_the_session_and_protects_secret_responses(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->withSession([
            'auth.password_confirmed_at' => now()->getTimestamp(),
        ]);

        $this->post(route('two-factor.enable'))
            ->assertRedirect()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private');

        $user->refresh();
        $this->assertNotNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_confirmed_at);
        $secret = Fortify::currentEncrypter()->decrypt($user->two_factor_secret);

        $this->get(route('profile.security'))
            ->assertOk()
            ->assertSee('Manual setup key')
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertHeader('Referrer-Policy', 'no-referrer');

        $response = $this->post(route('two-factor.confirm'), [
            'code' => $this->currentOtp($secret),
        ]);

        $response->assertRedirect();
        $response->assertSessionHas(
            EnsureTwoFactorAuthenticationIsConfigured::VERIFIED_USER_SESSION_KEY,
            $user->id,
        );
        $this->assertNotNull($user->fresh()->two_factor_confirmed_at);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'two_factor.enabled',
            'user_id' => $user->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'two_factor.confirmed',
            'user_id' => $user->id,
        ]);
    }

    public function test_required_roles_cannot_disable_two_factor_authentication(): void
    {
        $user = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $this->enableTwoFactorFor($user);

        $this->actingAs($user)->withSession([
            'auth.password_confirmed_at' => now()->getTimestamp(),
            EnsureTwoFactorAuthenticationIsConfigured::VERIFIED_AT_SESSION_KEY => now()->getTimestamp(),
            EnsureTwoFactorAuthenticationIsConfigured::VERIFIED_USER_SESSION_KEY => $user->id,
        ])->delete(route('two-factor.disable'))
            ->assertForbidden();

        $this->assertTrue($user->fresh()->hasEnabledTwoFactorAuthentication());
    }

    public function test_a_confirmed_user_cannot_force_rotate_their_two_factor_secret(): void
    {
        $user = User::factory()->create();
        $this->enableTwoFactorFor($user);
        $user->refresh();
        $originalSecret = $user->two_factor_secret;
        $originalRecoveryCodes = $user->two_factor_recovery_codes;
        $originalConfirmedAt = $user->two_factor_confirmed_at;

        $this->actingAs($user)->withSession([
            'auth.password_confirmed_at' => now()->getTimestamp(),
        ])->postJson(route('two-factor.enable'), [
            'force' => '1',
        ])->assertUnprocessable();

        $user->refresh();

        $this->assertSame($originalSecret, $user->two_factor_secret);
        $this->assertSame($originalRecoveryCodes, $user->two_factor_recovery_codes);
        $this->assertTrue($originalConfirmedAt->equalTo($user->two_factor_confirmed_at));
        $this->assertTrue($user->hasEnabledTwoFactorAuthentication());
    }

    public function test_optional_users_can_disable_and_regenerate_recovery_codes_with_audit_records(): void
    {
        $user = User::factory()->create();
        $this->enableTwoFactorFor($user);
        $originalCodes = $user->fresh()->two_factor_recovery_codes;

        $this->actingAs($user)->withSession([
            'auth.password_confirmed_at' => now()->getTimestamp(),
        ])->post(route('two-factor.regenerate-recovery-codes'))
            ->assertRedirect();

        $this->assertNotSame($originalCodes, $user->fresh()->two_factor_recovery_codes);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'two_factor.recovery_codes_regenerated',
            'user_id' => $user->id,
        ]);

        $this->delete(route('two-factor.disable'))->assertRedirect();

        $this->assertFalse($user->fresh()->hasEnabledTwoFactorAuthentication());
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'two_factor.disabled',
            'user_id' => $user->id,
        ]);
    }

    /** @return array{0: string, 1: list<string>} */
    private function enableTwoFactorFor(User $user): array
    {
        $secret = app(Google2FA::class)->generateSecretKey();
        $recoveryCodes = collect(range(1, 8))
            ->map(fn (): string => RecoveryCode::generate())
            ->all();

        $user->forceFill([
            'two_factor_secret' => Fortify::currentEncrypter()->encrypt($secret),
            'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(
                json_encode($recoveryCodes, JSON_THROW_ON_ERROR),
            ),
            'two_factor_confirmed_at' => now(),
        ])->save();

        return [$secret, $recoveryCodes];
    }

    private function beginTwoFactorLogin(User $user): void
    {
        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('two-factor.login'));

        $this->assertGuest();
    }

    private function currentOtp(string $secret): string
    {
        return app(Google2FA::class)->getCurrentOtp($secret);
    }
}
