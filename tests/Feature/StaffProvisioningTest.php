<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Http\Middleware\EnsureTwoFactorAuthenticationIsConfigured;
use App\Models\StaffInvitation;
use App\Models\User;
use App\Services\StaffInvitationNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use Tests\TestCase;

class StaffProvisioningTest extends TestCase
{
    use RefreshDatabase;

    private const STRONG_PASSWORD = 'Correct-Horse9!Battery';

    public function test_only_an_active_super_administrator_can_view_staff_administration(): void
    {
        $this->get(route('admin.staff.index'))->assertRedirect(route('login'));

        foreach ([UserRole::Manager, UserRole::Staff, UserRole::Driver, UserRole::Customer] as $role) {
            $user = User::factory()->create(['role' => $role]);

            $this->actingAs($user)
                ->get(route('admin.staff.index'))
                ->assertForbidden();
        }

        $administrator = $this->superAdministrator();

        $this->actingAs($administrator)
            ->withSession($this->securitySession($administrator))
            ->get(route('admin.staff.index'))
            ->assertOk();
    }

    public function test_staff_mutations_require_recent_password_confirmation(): void
    {
        Notification::fake();
        $administrator = $this->superAdministrator();

        $this->actingAs($administrator)
            ->withSession($this->securitySession($administrator, passwordConfirmed: false))
            ->post(route('admin.staff.store'), $this->invitationPayload())
            ->assertRedirect(route('password.confirm'));

        $this->assertDatabaseMissing('users', ['email' => 'team.member@example.com']);
        Notification::assertNothingSent();
    }

    public function test_staff_administration_enforces_configured_and_challenged_two_factor_authentication(): void
    {
        $notConfigured = $this->superAdministrator([
            'two_factor_secret' => null,
            'two_factor_confirmed_at' => null,
        ]);

        $this->actingAs($notConfigured)
            ->withSession(['auth.password_confirmed_at' => now()->timestamp])
            ->get(route('admin.staff.index'))
            ->assertRedirect(route('profile.security'));

        $configuredButNotChallenged = $this->superAdministrator();

        $this->actingAs($configuredButNotChallenged)
            ->withSession(['auth.password_confirmed_at' => now()->timestamp])
            ->get(route('admin.staff.index'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_invitation_normalizes_email_stores_only_a_hash_and_uses_the_canonical_app_url(): void
    {
        Notification::fake();
        config([
            'app.url' => 'https://canonical.pisfa.example/platform',
            'pisfa.staff.invitation_expiry_hours' => 24,
        ]);

        $administrator = $this->superAdministrator();
        $startedAt = now();

        $payload = $this->invitationPayload([
            'email' => '  Team.Member@Example.COM  ',
            'status' => AccountStatus::Active->value,
            'email_verified_at' => now()->toIso8601String(),
            'must_change_password' => false,
            'invited_by_user_id' => 999999,
            'password' => self::STRONG_PASSWORD,
        ]);

        $this->actingAs($administrator)
            ->withSession($this->securitySession($administrator))
            ->post(route('admin.staff.store'), $payload)
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.staff.index'));

        $staff = User::query()->where('email', 'team.member@example.com')->firstOrFail();
        $invitation = StaffInvitation::query()->whereBelongsTo($staff)->sole();
        $rawToken = $this->assertInvitationWasSentAndReturnToken($staff);

        $this->assertSame(UserRole::Staff, $staff->role);
        $this->assertSame(AccountStatus::Inactive, $staff->status);
        $this->assertNull($staff->email_verified_at);
        $this->assertTrue($staff->must_change_password);
        $this->assertFalse($staff->two_factor_required);
        $this->assertSame($administrator->id, $staff->invited_by_user_id);
        $this->assertFalse(Hash::check(self::STRONG_PASSWORD, $staff->password));
        $this->assertSame(hash('sha256', $rawToken), $invitation->token_hash);
        $this->assertNotSame($rawToken, $invitation->token_hash);
        $this->assertTrue($invitation->expires_at->betweenIncluded(
            $startedAt->copy()->addHours(24)->subSecond(),
            now()->addHours(24)->addSecond(),
        ));

        $storedInvitation = json_encode(DB::table('staff_invitations')->where('id', $invitation->id)->first());
        $storedAudits = json_encode(DB::table('audit_logs')->get());

        $this->assertStringNotContainsString($rawToken, (string) $storedInvitation);
        $this->assertStringNotContainsString($rawToken, (string) $storedAudits);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'staff.invited',
            'auditable_type' => User::class,
            'auditable_id' => $staff->id,
        ]);

        $tokenProperty = new ReflectionProperty(StaffInvitationNotification::class, 'rawToken');
        $this->assertTrue($tokenProperty->isPrivate());
    }

    public function test_manager_and_super_administrator_invitations_always_force_two_factor_authentication(): void
    {
        foreach ([UserRole::Manager, UserRole::SuperAdmin] as $role) {
            Notification::fake();
            $administrator = $this->superAdministrator();

            $this->actingAs($administrator)
                ->withSession($this->securitySession($administrator))
                ->post(route('admin.staff.store'), $this->invitationPayload([
                    'email' => $role->value.'@example.com',
                    'role' => $role->value,
                    'two_factor_required' => '0',
                ]))
                ->assertSessionHasNoErrors();

            $this->assertTrue(
                User::query()->where('email', $role->value.'@example.com')->firstOrFail()->two_factor_required,
            );
        }
    }

    public function test_customer_role_and_case_insensitive_duplicate_email_are_rejected(): void
    {
        Notification::fake();
        User::factory()->create(['email' => 'Existing.Person@Example.COM']);
        $administrator = $this->superAdministrator();

        $this->actingAs($administrator)
            ->withSession($this->securitySession($administrator))
            ->from(route('admin.staff.create'))
            ->post(route('admin.staff.store'), $this->invitationPayload([
                'email' => 'existing.person@example.com',
            ]))
            ->assertSessionHasErrors('email')
            ->assertRedirect(route('admin.staff.create'));

        $this->actingAs($administrator)
            ->withSession($this->securitySession($administrator))
            ->from(route('admin.staff.create'))
            ->post(route('admin.staff.store'), $this->invitationPayload([
                'email' => 'customer.staff@example.com',
                'role' => UserRole::Customer->value,
            ]))
            ->assertSessionHasErrors('role')
            ->assertRedirect(route('admin.staff.create'));

        Notification::assertNothingSent();
    }

    public function test_wrong_expired_and_suspended_invitation_links_cannot_activate_an_account(): void
    {
        $wrongTokenUser = $this->inactiveStaff('wrong@example.com');
        $this->pendingInvitation($wrongTokenUser, str_repeat('a', 64));

        $this->post(route('staff-invitations.accept', str_repeat('b', 64)), $this->passwordPayload())
            ->assertSessionHasErrors('invitation');

        $expiredUser = $this->inactiveStaff('expired@example.com');
        $expiredToken = str_repeat('c', 64);
        $this->pendingInvitation($expiredUser, $expiredToken, now()->subMinute());

        $this->get(route('staff-invitations.show', $expiredToken))->assertGone();
        $this->post(route('staff-invitations.accept', $expiredToken), $this->passwordPayload())
            ->assertSessionHasErrors('invitation');

        $suspendedUser = $this->inactiveStaff('suspended@example.com', AccountStatus::Suspended);
        $suspendedToken = str_repeat('d', 64);
        $this->pendingInvitation($suspendedUser, $suspendedToken);

        $this->post(route('staff-invitations.accept', $suspendedToken), $this->passwordPayload())
            ->assertSessionHasErrors('invitation');

        $this->assertSame(AccountStatus::Inactive, $wrongTokenUser->refresh()->status);
        $this->assertSame(AccountStatus::Inactive, $expiredUser->refresh()->status);
        $this->assertSame(AccountStatus::Suspended, $suspendedUser->refresh()->status);
        $this->assertGuest();
    }

    public function test_acceptance_requires_a_strong_confirmed_password(): void
    {
        $staff = $this->inactiveStaff('weak-password@example.com');
        $token = str_repeat('e', 64);
        $this->pendingInvitation($staff, $token);

        $this->post(route('staff-invitations.accept', $token), [
            'password' => 'weakpass',
            'password_confirmation' => 'weakpass',
        ])->assertSessionHasErrors('password');

        $this->assertSame(AccountStatus::Inactive, $staff->refresh()->status);
        $this->assertNull($staff->email_verified_at);
        $this->assertGuest();
    }

    public function test_acceptance_is_single_use_ignores_forged_fields_and_regenerates_the_session(): void
    {
        $staff = $this->inactiveStaff('accept@example.com', role: UserRole::Manager);
        $staff->forceFill(['two_factor_required' => true])->save();
        $token = str_repeat('f', 64);
        $invitation = $this->pendingInvitation($staff, $token);
        $sibling = $this->pendingInvitation($staff, str_repeat('1', 64));
        DB::table('sessions')->insert([
            'id' => 'stale-pre-activation-session',
            'user_id' => $staff->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Test',
            'payload' => 'payload',
            'last_activity' => now()->timestamp,
        ]);

        $this->withSession(['invitation_probe' => true]);
        $oldSessionId = $this->app['session']->getId();

        $this->post(route('staff-invitations.accept', $token), $this->passwordPayload([
            'email' => 'forged@example.com',
            'role' => UserRole::SuperAdmin->value,
            'status' => AccountStatus::Suspended->value,
            'two_factor_required' => false,
        ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.security'));

        $newSessionId = $this->app['session']->getId();
        $staff->refresh();

        $this->assertNotSame($oldSessionId, $newSessionId);
        $this->assertAuthenticatedAs($staff);
        $this->assertSame('accept@example.com', $staff->email);
        $this->assertSame(UserRole::Manager, $staff->role);
        $this->assertSame(AccountStatus::Active, $staff->status);
        $this->assertNotNull($staff->email_verified_at);
        $this->assertFalse($staff->must_change_password);
        $this->assertTrue($staff->two_factor_required);
        $this->assertTrue(Hash::check(self::STRONG_PASSWORD, $staff->password));
        $this->assertNotNull($invitation->refresh()->accepted_at);
        $this->assertNotNull($sibling->refresh()->revoked_at);
        $this->assertDatabaseMissing('sessions', ['id' => 'stale-pre-activation-session']);

        $audit = DB::table('audit_logs')->where('event', 'staff.invitation_accepted')->first();
        $this->assertNotNull($audit);
        $this->assertStringContainsString('[REDACTED]', (string) $audit->url);
        $this->assertStringNotContainsString($token, (string) json_encode($audit));

        Auth::logout();

        $this->post(route('staff-invitations.accept', $token), $this->passwordPayload())
            ->assertSessionHasErrors('invitation');

        $this->assertGuest();
    }

    public function test_optional_staff_invitation_acceptance_redirects_to_the_dashboard(): void
    {
        $staff = $this->inactiveStaff('optional-two-factor@example.com');
        $token = str_repeat('2', 64);
        $this->pendingInvitation($staff, $token);

        $this->post(route('staff-invitations.accept', $token), $this->passwordPayload())
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('dashboard'));
    }

    public function test_resend_atomically_revokes_the_old_link_and_issues_one_new_hashed_link(): void
    {
        Notification::fake();
        config(['app.url' => 'https://canonical.pisfa.example']);
        $administrator = $this->superAdministrator();
        $staff = $this->inactiveStaff('resend@example.com');
        $oldToken = str_repeat('3', 64);
        $oldInvitation = $this->pendingInvitation($staff, $oldToken);

        $this->actingAs($administrator)
            ->withSession($this->securitySession($administrator))
            ->post(route('admin.staff.resend', $staff))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $newToken = $this->assertInvitationWasSentAndReturnToken($staff);
        $oldInvitation->refresh();
        $newInvitation = StaffInvitation::query()
            ->whereBelongsTo($staff)
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->sole();

        $this->assertNotNull($oldInvitation->revoked_at);
        $this->assertNotSame($oldToken, $newToken);
        $this->assertSame(hash('sha256', $newToken), $newInvitation->token_hash);

        Auth::logout();

        $this->get(route('staff-invitations.show', $oldToken))->assertGone();
        $this->get(route('staff-invitations.show', $newToken))->assertOk();
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'staff.invitation_resent',
            'auditable_id' => $staff->id,
        ]);
    }

    public function test_suspended_or_previously_activated_accounts_cannot_receive_a_resent_invitation(): void
    {
        Notification::fake();
        $administrator = $this->superAdministrator();
        $suspended = $this->inactiveStaff('no-resend-suspended@example.com', AccountStatus::Suspended);
        $this->pendingInvitation($suspended, str_repeat('4', 64));

        $this->actingAs($administrator)
            ->withSession($this->securitySession($administrator))
            ->post(route('admin.staff.resend', $suspended))
            ->assertSessionHasErrors('invitation');

        $activated = $this->inactiveStaff('no-resend-activated@example.com');
        $accepted = $this->pendingInvitation($activated, str_repeat('5', 64));
        $accepted->forceFill(['accepted_at' => now()])->save();

        $this->actingAs($administrator)
            ->withSession($this->securitySession($administrator))
            ->post(route('admin.staff.resend', $activated))
            ->assertSessionHasErrors('invitation');

        Notification::assertNothingSent();
        $this->assertSame(2, StaffInvitation::query()->count());
    }

    public function test_access_update_ignores_forged_identity_fields_and_revokes_remember_and_database_sessions(): void
    {
        $administrator = $this->superAdministrator();
        $staff = User::factory()->create([
            'name' => 'Original Name',
            'email' => 'access-update@example.com',
            'role' => UserRole::Staff,
            'status' => AccountStatus::Active,
            'two_factor_required' => false,
            'remember_token' => 'original-remember-token',
        ]);
        DB::table('sessions')->insert([
            [
                'id' => 'staff-session-one',
                'user_id' => $staff->id,
                'ip_address' => '127.0.0.1',
                'user_agent' => 'Test',
                'payload' => 'payload',
                'last_activity' => now()->timestamp,
            ],
            [
                'id' => 'staff-session-two',
                'user_id' => $staff->id,
                'ip_address' => '127.0.0.1',
                'user_agent' => 'Test',
                'payload' => 'payload',
                'last_activity' => now()->timestamp,
            ],
        ]);

        $this->actingAs($administrator)
            ->withSession($this->securitySession($administrator))
            ->patch(route('admin.staff.update', $staff), [
                'role' => UserRole::Manager->value,
                'status' => AccountStatus::Suspended->value,
                'two_factor_required' => '0',
                'name' => 'Forged Name',
                'email' => 'forged@example.com',
                'remember_token' => 'forged-token',
                'email_verified_at' => null,
            ])
            ->assertSessionHasNoErrors();

        $staff->refresh();
        $this->assertSame('Original Name', $staff->name);
        $this->assertSame('access-update@example.com', $staff->email);
        $this->assertSame(UserRole::Manager, $staff->role);
        $this->assertSame(AccountStatus::Suspended, $staff->status);
        $this->assertTrue($staff->two_factor_required);
        $this->assertNotSame('original-remember-token', $staff->remember_token);
        $this->assertNotSame('forged-token', $staff->remember_token);
        $this->assertDatabaseMissing('sessions', ['user_id' => $staff->id]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'staff.access_updated',
            'auditable_id' => $staff->id,
        ]);
    }

    public function test_an_administrator_cannot_demote_or_deactivate_self_and_one_active_super_admin_remains(): void
    {
        $administrator = $this->superAdministrator();

        $this->actingAs($administrator)
            ->withSession($this->securitySession($administrator))
            ->patch(route('admin.staff.update', $administrator), [
                'role' => UserRole::Manager->value,
                'status' => AccountStatus::Active->value,
                'two_factor_required' => '1',
            ])
            ->assertSessionHasErrors('role');

        $this->actingAs($administrator)
            ->withSession($this->securitySession($administrator))
            ->patch(route('admin.staff.update', $administrator), [
                'role' => UserRole::SuperAdmin->value,
                'status' => AccountStatus::Suspended->value,
                'two_factor_required' => '1',
            ])
            ->assertSessionHasErrors('role');

        $administrator->refresh();
        $this->assertSame(UserRole::SuperAdmin, $administrator->role);
        $this->assertSame(AccountStatus::Active, $administrator->status);
        $this->assertSame(1, User::query()
            ->where('role', UserRole::SuperAdmin->value)
            ->where('status', AccountStatus::Active->value)
            ->count());
    }

    public function test_an_administrator_can_remove_another_super_admin_only_when_one_will_remain(): void
    {
        $administrator = $this->superAdministrator();
        $otherAdministrator = $this->superAdministrator();

        $this->actingAs($administrator)
            ->withSession($this->securitySession($administrator))
            ->patch(route('admin.staff.update', $otherAdministrator), [
                'role' => UserRole::Staff->value,
                'status' => AccountStatus::Suspended->value,
                'two_factor_required' => '0',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, User::query()
            ->where('role', UserRole::SuperAdmin->value)
            ->where('status', AccountStatus::Active->value)
            ->count());
    }

    /** @return array<string, array{UserRole}> */
    public static function staffRoles(): array
    {
        return [
            'super administrator' => [UserRole::SuperAdmin],
            'manager' => [UserRole::Manager],
            'staff' => [UserRole::Staff],
            'driver' => [UserRole::Driver],
        ];
    }

    #[DataProvider('staffRoles')]
    public function test_team_accounts_cannot_bypass_access_management_by_self_deleting(UserRole $role): void
    {
        $staff = User::factory()->create(['role' => $role]);

        $this->actingAs($staff)
            ->from(route('profile.edit'))
            ->delete(route('profile.destroy'), ['password' => 'password'])
            ->assertSessionHasErrorsIn('userDeletion', 'password')
            ->assertRedirect(route('profile.edit'));

        $this->assertNotNull($staff->fresh());
        $this->assertAuthenticatedAs($staff);
    }

    public function test_create_super_admin_command_has_no_password_argument_and_requires_explicit_confirmation(): void
    {
        $command = Artisan::all()['pisfa:create-super-admin'];

        $this->assertFalse($command->getDefinition()->hasArgument('password'));
        $this->assertFalse($command->getDefinition()->hasOption('password'));

        $this->artisan('pisfa:create-super-admin')
            ->expectsQuestion('Full name', 'Command Administrator')
            ->expectsQuestion('Email address', 'COMMAND.ADMIN@EXAMPLE.COM')
            ->expectsQuestion('Password (at least 12 characters with upper/lowercase, a number, and a symbol)', self::STRONG_PASSWORD)
            ->expectsQuestion('Confirm password', self::STRONG_PASSWORD)
            ->expectsConfirmation('Create this active, email-verified super administrator?', 'no')
            ->expectsOutput('No account was created.')
            ->assertSuccessful();

        $this->assertDatabaseMissing('users', ['email' => 'command.admin@example.com']);
    }

    public function test_create_super_admin_command_validates_a_strong_confirmed_password_and_audits_success(): void
    {
        $this->artisan('pisfa:create-super-admin')
            ->expectsQuestion('Full name', 'Command Administrator')
            ->expectsQuestion('Email address', 'COMMAND.ADMIN@EXAMPLE.COM')
            ->expectsQuestion('Password (at least 12 characters with upper/lowercase, a number, and a symbol)', self::STRONG_PASSWORD)
            ->expectsQuestion('Confirm password', self::STRONG_PASSWORD)
            ->expectsConfirmation('Create this active, email-verified super administrator?', 'yes')
            ->assertSuccessful();

        $administrator = User::query()->where('email', 'command.admin@example.com')->firstOrFail();

        $this->assertSame(UserRole::SuperAdmin, $administrator->role);
        $this->assertSame(AccountStatus::Active, $administrator->status);
        $this->assertNotNull($administrator->email_verified_at);
        $this->assertTrue($administrator->two_factor_required);
        $this->assertTrue(Hash::check(self::STRONG_PASSWORD, $administrator->password));
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'staff.super_admin_bootstrapped',
            'user_id' => $administrator->id,
            'auditable_id' => $administrator->id,
        ]);
    }

    public function test_staff_migration_can_roll_back_and_reapply_on_sqlite(): void
    {
        $migration = require database_path('migrations/2026_08_04_140000_add_staff_provisioning_fields.php');

        $migration->down();

        $this->assertFalse(Schema::hasTable('staff_invitations'));
        $this->assertFalse(Schema::hasColumn('users', 'invited_by_user_id'));
        $this->assertFalse(Schema::hasColumn('users', 'must_change_password'));
        $this->assertFalse(Schema::hasColumn('users', 'two_factor_required'));

        $migration->up();

        $this->assertTrue(Schema::hasTable('staff_invitations'));
        $this->assertTrue(Schema::hasColumn('users', 'invited_by_user_id'));
        $this->assertTrue(Schema::hasColumn('users', 'must_change_password'));
        $this->assertTrue(Schema::hasColumn('users', 'two_factor_required'));
    }

    /** @param array<string, mixed> $overrides */
    private function superAdministrator(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'role' => UserRole::SuperAdmin,
            'status' => AccountStatus::Active,
            'email_verified_at' => now(),
            'two_factor_required' => true,
            'two_factor_secret' => 'configured-for-feature-test',
            'two_factor_confirmed_at' => now(),
        ], $overrides));
    }

    /** @return array<string, int> */
    private function securitySession(User $administrator, bool $passwordConfirmed = true): array
    {
        $session = [
            EnsureTwoFactorAuthenticationIsConfigured::VERIFIED_AT_SESSION_KEY => now()->timestamp,
            EnsureTwoFactorAuthenticationIsConfigured::VERIFIED_USER_SESSION_KEY => $administrator->id,
        ];

        if ($passwordConfirmed) {
            $session['auth.password_confirmed_at'] = now()->timestamp;
        }

        return $session;
    }

    /** @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function invitationPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Team Member',
            'email' => 'team.member@example.com',
            'phone' => '+256700123456',
            'role' => UserRole::Staff->value,
            'two_factor_required' => '0',
        ], $overrides);
    }

    /** @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function passwordPayload(array $overrides = []): array
    {
        return array_merge([
            'password' => self::STRONG_PASSWORD,
            'password_confirmation' => self::STRONG_PASSWORD,
        ], $overrides);
    }

    private function inactiveStaff(
        string $email,
        AccountStatus $status = AccountStatus::Inactive,
        UserRole $role = UserRole::Staff,
    ): User {
        return User::factory()->unverified()->create([
            'email' => $email,
            'role' => $role,
            'status' => $status,
            'must_change_password' => true,
            'two_factor_required' => in_array($role, [UserRole::SuperAdmin, UserRole::Manager], true),
        ]);
    }

    private function pendingInvitation(
        User $staff,
        string $rawToken,
        mixed $expiresAt = null,
    ): StaffInvitation {
        return StaffInvitation::query()->create([
            'user_id' => $staff->id,
            'invited_by_user_id' => null,
            'token_hash' => hash('sha256', $rawToken),
            'expires_at' => $expiresAt ?? now()->addHours(72),
        ]);
    }

    private function assertInvitationWasSentAndReturnToken(User $staff): string
    {
        $invitationUrl = null;

        Notification::assertSentTo(
            $staff,
            StaffInvitationNotification::class,
            function (StaffInvitationNotification $notification) use (&$invitationUrl): bool {
                $this->assertNotInstanceOf(ShouldQueue::class, $notification);
                $invitationUrl = $notification->invitationUrl();

                return true;
            },
        );

        $this->assertIsString($invitationUrl);
        $this->assertStringStartsWith(rtrim((string) config('app.url'), '/').'/staff/invitations/', $invitationUrl);

        $path = (string) parse_url($invitationUrl, PHP_URL_PATH);
        $token = rawurldecode((string) basename($path));

        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $token);

        return $token;
    }
}
