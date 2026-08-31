<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Enums\StaffRoles;
use App\Enums\UserRole;
use App\Models\StaffInvitation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StaffInvitationService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @param  array{name: string, email: string, phone?: ?string}  $attributes
     */
    public function invite(
        User $actor,
        array $attributes,
        UserRole $role,
        bool $twoFactorRequired,
    ): StaffInvitation {
        $this->ensureActorCanManage($actor);

        if (! StaffRoles::isManageable($role)) {
            throw ValidationException::withMessages([
                'role' => 'Customers cannot be provisioned through staff invitations.',
            ]);
        }

        $email = mb_strtolower(trim($attributes['email']));

        if (User::query()->whereRaw('LOWER(email) = ?', [$email])->exists()) {
            throw ValidationException::withMessages([
                'email' => 'An account already exists for this email address.',
            ]);
        }

        $issued = DB::transaction(function () use ($actor, $attributes, $email, $role, $twoFactorRequired): IssuedStaffInvitation {
            $lockedActor = User::query()
                ->whereKey($actor->getKey())
                ->lockForUpdate()
                ->first();

            if ($lockedActor === null) {
                throw new AuthorizationException;
            }

            $this->ensureActorCanManage($lockedActor);

            $user = new User;
            $user->forceFill([
                'invited_by_user_id' => $lockedActor->getKey(),
                'name' => trim($attributes['name']),
                'email' => $email,
                'phone' => filled($attributes['phone'] ?? null) ? trim((string) $attributes['phone']) : null,
                'password' => Hash::make(Str::password(64)),
                'role' => $role,
                'status' => AccountStatus::Inactive,
                'preferred_language' => 'en',
                'preferred_currency' => 'UGX',
                'email_verified_at' => null,
                'must_change_password' => true,
                'two_factor_required' => StaffRoles::requiresTwoFactor($role) || $twoFactorRequired,
            ])->save();

            $issued = $this->newInvitation($user, $lockedActor);

            $this->auditLogger->record(
                event: 'staff.invited',
                auditable: $user,
                newValues: [
                    'email' => $user->email,
                    'role' => $role->value,
                    'status' => AccountStatus::Inactive->value,
                    'two_factor_required' => (bool) $user->two_factor_required,
                    'invitation_expires_at' => $issued->invitation->expires_at->toIso8601String(),
                ],
                user: $lockedActor,
            );

            return $issued;
        }, 3);

        $issued->invitation->user->notify($issued->notification());

        return $issued->invitation;
    }

    public function preview(string $rawToken): ?StaffInvitation
    {
        $tokenHash = $this->tokenHash($rawToken);

        if ($tokenHash === null) {
            return null;
        }

        $invitation = StaffInvitation::query()
            ->with('user')
            ->where('token_hash', $tokenHash)
            ->first();

        if ($invitation === null
            || ! hash_equals($invitation->token_hash, $tokenHash)
            || ! $invitation->isPending()) {
            return null;
        }

        return $invitation;
    }

    public function accept(string $rawToken, string $password): User
    {
        $tokenHash = $this->tokenHash($rawToken);

        if ($tokenHash === null) {
            $this->invalidInvitation();
        }

        return DB::transaction(function () use ($tokenHash, $password): User {
            $invitation = StaffInvitation::query()
                ->where('token_hash', $tokenHash)
                ->lockForUpdate()
                ->first();

            if ($invitation === null
                || ! hash_equals($invitation->token_hash, $tokenHash)
                || ! $invitation->isPending()) {
                $this->invalidInvitation();
            }

            $user = User::query()->whereKey($invitation->user_id)->lockForUpdate()->first();

            if ($user === null
                || ! StaffRoles::isManageable($user->role)
                || $user->status !== AccountStatus::Inactive) {
                $this->invalidInvitation();
            }

            $oldValues = [
                'status' => $user->status->value,
                'email_verified' => $user->email_verified_at !== null,
                'must_change_password' => (bool) $user->must_change_password,
            ];

            $user->forceFill([
                'password' => Hash::make($password),
                'status' => AccountStatus::Active,
                'email_verified_at' => now(),
                'must_change_password' => false,
                'remember_token' => Str::random(60),
            ])->save();

            $revokedSessions = DB::table('sessions')
                ->where('user_id', $user->getKey())
                ->delete();

            $invitation->forceFill(['accepted_at' => now()])->save();

            StaffInvitation::query()
                ->where('user_id', $user->getKey())
                ->whereKeyNot($invitation->getKey())
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now(), 'updated_at' => now()]);

            $this->auditLogger->record(
                event: 'staff.invitation_accepted',
                auditable: $user,
                oldValues: $oldValues,
                newValues: [
                    'status' => AccountStatus::Active->value,
                    'email_verified' => true,
                    'must_change_password' => false,
                    'sessions_revoked' => $revokedSessions,
                ],
                context: ['url' => '/staff/invitations/[REDACTED]'],
                user: $user,
            );

            return $user->fresh();
        }, 3);
    }

    public function resend(User $actor, User $staff): StaffInvitation
    {
        $this->ensureActorCanManage($actor);

        $issued = DB::transaction(function () use ($actor, $staff): IssuedStaffInvitation {
            $lockedActor = User::query()
                ->whereKey($actor->getKey())
                ->lockForUpdate()
                ->first();

            if ($lockedActor === null) {
                throw new AuthorizationException;
            }

            $this->ensureActorCanManage($lockedActor);

            $invitations = StaffInvitation::query()
                ->where('user_id', $staff->getKey())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $lockedStaff = User::query()->whereKey($staff->getKey())->lockForUpdate()->firstOrFail();

            if (! StaffRoles::isManageable($lockedStaff->role)) {
                throw new AuthorizationException;
            }

            if ($lockedStaff->status !== AccountStatus::Inactive || $invitations->contains(
                fn (StaffInvitation $invitation): bool => $invitation->accepted_at !== null,
            )) {
                throw ValidationException::withMessages([
                    'invitation' => 'Invitations can only be resent for inactive accounts that have not been activated.',
                ]);
            }

            StaffInvitation::query()
                ->where('user_id', $lockedStaff->getKey())
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now(), 'updated_at' => now()]);

            $issued = $this->newInvitation($lockedStaff, $lockedActor);

            $this->auditLogger->record(
                event: 'staff.invitation_resent',
                auditable: $lockedStaff,
                newValues: [
                    'email' => $lockedStaff->email,
                    'invitation_expires_at' => $issued->invitation->expires_at->toIso8601String(),
                ],
                user: $lockedActor,
            );

            return $issued;
        }, 3);

        $issued->invitation->user->notify($issued->notification());

        return $issued->invitation;
    }

    public function updateAccess(
        User $actor,
        User $staff,
        UserRole $role,
        AccountStatus $status,
        bool $twoFactorRequired,
    ): User {
        $this->ensureActorCanManage($actor);

        if (! StaffRoles::isManageable($role)) {
            throw ValidationException::withMessages(['role' => 'Select a staff role.']);
        }

        return DB::transaction(function () use ($actor, $staff, $role, $status, $twoFactorRequired): User {
            $activeAdministratorIds = User::query()
                ->where('role', UserRole::SuperAdmin->value)
                ->where('status', AccountStatus::Active->value)
                ->orderBy('id')
                ->lockForUpdate()
                ->pluck('id');

            if (! $activeAdministratorIds->contains($actor->getKey())) {
                throw new AuthorizationException;
            }

            $lockedStaff = User::query()->whereKey($staff->getKey())->lockForUpdate()->firstOrFail();

            if (! StaffRoles::isManageable($lockedStaff->role)) {
                throw new AuthorizationException;
            }

            if ($lockedStaff->is($actor)
                && ($role !== UserRole::SuperAdmin || $status !== AccountStatus::Active)) {
                throw ValidationException::withMessages([
                    'role' => 'You cannot demote or deactivate your own administrator account.',
                ]);
            }

            if ($status === AccountStatus::Active && $lockedStaff->email_verified_at === null) {
                throw ValidationException::withMessages([
                    'status' => 'The team member must accept the invitation before activation.',
                ]);
            }

            $removesActiveSuperAdministrator = $lockedStaff->role === UserRole::SuperAdmin
                && $lockedStaff->status === AccountStatus::Active
                && ($role !== UserRole::SuperAdmin || $status !== AccountStatus::Active);

            if ($removesActiveSuperAdministrator && $activeAdministratorIds->count() <= 1) {
                throw ValidationException::withMessages([
                    'role' => 'At least one active super administrator must remain.',
                ]);
            }

            $oldValues = [
                'role' => $lockedStaff->role->value,
                'status' => $lockedStaff->status->value,
                'two_factor_required' => (bool) $lockedStaff->two_factor_required,
            ];

            $lockedStaff->forceFill([
                'role' => $role,
                'status' => $status,
                'two_factor_required' => StaffRoles::requiresTwoFactor($role) || $twoFactorRequired,
                'remember_token' => Str::random(60),
            ])->save();

            $revokedSessions = DB::table('sessions')
                ->where('user_id', $lockedStaff->getKey())
                ->delete();

            $this->auditLogger->record(
                event: 'staff.access_updated',
                auditable: $lockedStaff,
                oldValues: $oldValues,
                newValues: [
                    'role' => $role->value,
                    'status' => $status->value,
                    'two_factor_required' => (bool) $lockedStaff->two_factor_required,
                    'sessions_revoked' => $revokedSessions,
                ],
                user: $actor,
            );

            return $lockedStaff->fresh();
        }, 3);
    }

    private function newInvitation(User $staff, User $actor): IssuedStaffInvitation
    {
        $rawToken = bin2hex(random_bytes(32));
        $expiryHours = max(1, (int) config('pisfa.staff.invitation_expiry_hours', 72));

        $invitation = StaffInvitation::query()->create([
            'user_id' => $staff->getKey(),
            'invited_by_user_id' => $actor->getKey(),
            'token_hash' => hash('sha256', $rawToken),
            'expires_at' => now()->addHours($expiryHours),
        ]);
        $invitation->setRelation('user', $staff);

        return new IssuedStaffInvitation($invitation, $rawToken);
    }

    private function tokenHash(string $rawToken): ?string
    {
        $rawToken = trim($rawToken);

        if (! preg_match('/\A[a-f0-9]{64}\z/i', $rawToken)) {
            return null;
        }

        return hash('sha256', $rawToken);
    }

    /** @return never */
    private function invalidInvitation(): void
    {
        throw ValidationException::withMessages([
            'invitation' => 'This invitation is invalid or no longer available.',
        ]);
    }

    private function ensureActorCanManage(User $actor): void
    {
        if (! $actor->hasRole(UserRole::SuperAdmin) || ! $actor->isActive()) {
            throw new AuthorizationException;
        }
    }
}
