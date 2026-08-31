<?php

namespace App\Listeners;

use App\Actions\Fortify\RedirectIfTwoFactorAuthenticatable;
use App\Http\Middleware\EnsureTwoFactorAuthenticationIsConfigured;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Laravel\Fortify\Events\RecoveryCodeReplaced;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationChallenged;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;
use Laravel\Fortify\Events\ValidTwoFactorAuthenticationCodeProvided;

class RecordTwoFactorSecurityEvent
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly Request $request,
    ) {}

    public function handle(object $event): void
    {
        $user = $event->user ?? null;

        if (! $user instanceof User) {
            return;
        }

        if ($event instanceof ValidTwoFactorAuthenticationCodeProvided ||
            $event instanceof TwoFactorAuthenticationConfirmed) {
            $this->markSessionAsVerified($user);
        }

        if ($event instanceof TwoFactorAuthenticationDisabled && $this->request->hasSession()) {
            $this->request->session()->forget([
                EnsureTwoFactorAuthenticationIsConfigured::VERIFIED_AT_SESSION_KEY,
                EnsureTwoFactorAuthenticationIsConfigured::VERIFIED_USER_SESSION_KEY,
            ]);
        }

        if ($event instanceof ValidTwoFactorAuthenticationCodeProvided && $this->request->hasSession()) {
            // Leave login.remember for Fortify to consume after this event.
            $this->request->session()->forget([
                RedirectIfTwoFactorAuthenticatable::PENDING_EXPIRES_AT_KEY,
                RedirectIfTwoFactorAuthenticatable::PENDING_STATE_HASH_KEY,
            ]);
        }

        $auditEvent = match (true) {
            $event instanceof TwoFactorAuthenticationChallenged => 'two_factor.challenge_started',
            $event instanceof TwoFactorAuthenticationEnabled => 'two_factor.enabled',
            $event instanceof TwoFactorAuthenticationConfirmed => 'two_factor.confirmed',
            $event instanceof TwoFactorAuthenticationDisabled => 'two_factor.disabled',
            $event instanceof RecoveryCodesGenerated => 'two_factor.recovery_codes_regenerated',
            $event instanceof RecoveryCodeReplaced => 'two_factor.recovery_code_used',
            $event instanceof ValidTwoFactorAuthenticationCodeProvided => 'two_factor.challenge_succeeded',
            $event instanceof TwoFactorAuthenticationFailed => 'two_factor.challenge_failed',
            default => null,
        };

        if ($auditEvent === null) {
            return;
        }

        $context = [];

        if ($event instanceof ValidTwoFactorAuthenticationCodeProvided ||
            $event instanceof TwoFactorAuthenticationFailed) {
            $context['authentication_method'] = $this->request->filled('recovery_code')
                ? 'recovery_code'
                : 'authenticator_code';
        }

        $this->auditLogger->record(
            event: $auditEvent,
            auditable: $user,
            context: $context,
            user: $user,
        );
    }

    private function markSessionAsVerified(User $user): void
    {
        if (! $this->request->hasSession()) {
            return;
        }

        $this->request->session()->put([
            EnsureTwoFactorAuthenticationIsConfigured::VERIFIED_AT_SESSION_KEY => now()->getTimestamp(),
            EnsureTwoFactorAuthenticationIsConfigured::VERIFIED_USER_SESSION_KEY => $user->getKey(),
        ]);
    }
}
