<?php

namespace App\Actions\Corporate;

use App\Enums\AccountStatus;
use App\Enums\CorporateMemberRole;
use App\Enums\UserRole;
use App\Models\CorporateAccount;
use App\Models\CorporateMember;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Who is on a company account, and what they may do.
 *
 * Membership is added and revoked, never deleted: the bookings somebody raised
 * stay theirs, and the record of who could do what when is part of how a
 * disputed booking gets explained.
 */
class ManageCorporateMembers
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * Adds somebody, or restores and re-roles an existing membership.
     *
     * Restoring rather than inserting matters because the unique index would
     * refuse a second row anyway — and quietly failing on a rehire would be a
     * confusing way to find that out.
     */
    public function add(
        User $actor,
        CorporateAccount $account,
        User $person,
        CorporateMemberRole $role,
        ?string $jobTitle = null,
    ): CorporateMember {
        $jobTitle = filled($jobTitle) ? trim($jobTitle) : null;

        Validator::make(
            ['job_title' => $jobTitle, 'role' => $role->value],
            [
                'job_title' => ['nullable', 'string', 'max:120'],
                'role' => ['required', Rule::enum(CorporateMemberRole::class)],
            ],
        )->validate();

        return DB::transaction(function () use ($actor, $account, $person, $role, $jobTitle): CorporateMember {
            $lockedActor = $this->authorisedActor($actor, $account);

            $lockedAccount = CorporateAccount::query()
                ->whereKey($account->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $lockedAccount->status->isEditable()) {
                throw ValidationException::withMessages([
                    'account' => 'A closed account cannot take new members.',
                ]);
            }

            $lockedPerson = User::query()
                ->whereKey($person->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            // Only customers sit on a company account. Putting PISFA staff on
            // one would blur whose side somebody is acting for.
            if ($lockedPerson->status !== AccountStatus::Active
                || ! $lockedPerson->hasRole(UserRole::Customer)) {
                throw ValidationException::withMessages([
                    'user_id' => 'Only an active customer account can be added to a company.',
                ]);
            }

            $member = CorporateMember::query()
                ->where('corporate_account_id', $lockedAccount->getKey())
                ->where('user_id', $lockedPerson->getKey())
                ->lockForUpdate()
                ->first() ?? new CorporateMember;

            $previousRole = $member->exists ? $member->role->value : null;

            $member->forceFill([
                'corporate_account_id' => $lockedAccount->getKey(),
                'user_id' => $lockedPerson->getKey(),
                'role' => $role,
                'job_title' => $jobTitle,
                'is_active' => true,
                'deactivated_at' => null,
                'invited_by_user_id' => $member->exists
                    ? $member->invited_by_user_id
                    : $lockedActor->getKey(),
            ])->save();

            $this->auditLogger->record(
                event: $previousRole === null ? 'corporate_member.added' : 'corporate_member.role_changed',
                auditable: $member,
                oldValues: $previousRole === null ? [] : ['role' => $previousRole],
                newValues: [
                    'corporate_account_id' => $lockedAccount->getKey(),
                    'user_id' => $lockedPerson->getKey(),
                    'role' => $role->value,
                ],
                user: $lockedActor,
            );

            return $member->fresh(['account', 'user']);
        }, 3);
    }

    /**
     * Revokes somebody's authority without erasing them.
     *
     * The last active administrator cannot be removed: an account with nobody
     * able to manage it would need PISFA staff to intervene for every change,
     * which is exactly what a self-service account is meant to avoid.
     */
    public function deactivate(User $actor, CorporateAccount $account, CorporateMember $member): CorporateMember
    {
        return DB::transaction(function () use ($actor, $account, $member): CorporateMember {
            $lockedActor = $this->authorisedActor($actor, $account);

            $locked = CorporateMember::query()
                ->whereKey($member->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $locked->corporate_account_id !== (int) $account->getKey()) {
                throw ValidationException::withMessages([
                    'member' => 'That membership belongs to a different company.',
                ]);
            }

            if (! $locked->is_active) {
                return $locked;
            }

            if ($locked->role === CorporateMemberRole::Administrator) {
                $otherAdministrators = CorporateMember::query()
                    ->where('corporate_account_id', $account->getKey())
                    ->where('role', CorporateMemberRole::Administrator->value)
                    ->where('is_active', true)
                    ->whereKeyNot($locked->getKey())
                    ->exists();

                if (! $otherAdministrators) {
                    throw ValidationException::withMessages([
                        'member' => 'This is the only administrator on the account. '
                            .'Make somebody else an administrator first.',
                    ]);
                }
            }

            $locked->forceFill([
                'is_active' => false,
                'deactivated_at' => now(),
            ])->save();

            $this->auditLogger->record(
                event: 'corporate_member.deactivated',
                auditable: $locked,
                oldValues: ['is_active' => true],
                newValues: ['is_active' => false, 'role' => $locked->role->value],
                user: $lockedActor,
            );

            return $locked->fresh(['account', 'user']);
        }, 3);
    }

    /**
     * PISFA staff, or an administrator on the account itself.
     *
     * Both are locked and re-checked here rather than trusted from the
     * controller, so a membership revoked mid-request is honoured.
     */
    private function authorisedActor(User $actor, CorporateAccount $account): User
    {
        $locked = User::query()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();

        if (! CorporateAccess::canManageMembersOf($locked, $account)) {
            throw new AuthorizationException;
        }

        return $locked;
    }
}
