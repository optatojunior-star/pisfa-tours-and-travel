<?php

namespace App\Models;

use App\Enums\CorporateMemberRole;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Somebody's authority to act for a company.
 *
 * A row rather than a flag on the user: the same person may be an administrator
 * at one company and a traveller at another, and revoking authority has to be
 * possible without touching the bookings they already raised.
 *
 * @property CorporateMemberRole $role
 * @property bool $is_active
 * @property int $corporate_account_id
 * @property int $user_id
 * @property string|null $job_title
 * @property CarbonImmutable|null $deactivated_at
 * @property CorporateAccount|null $account
 * @property User|null $user
 */
class CorporateMember extends Model
{
    use HasFactory;

    protected $fillable = [
        'corporate_account_id',
        'user_id',
        'role',
        'job_title',
        'is_active',
        'deactivated_at',
        'invited_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'role' => CorporateMemberRole::class,
            'is_active' => 'boolean',
            'deactivated_at' => 'immutable_datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(CorporateAccount::class, 'corporate_account_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Whether this membership currently grants the authority.
     *
     * A deactivated membership grants nothing regardless of the role it holds,
     * which is why every check goes through here rather than reading `role`.
     */
    public function canBook(): bool
    {
        return $this->is_active && $this->role->canBook();
    }

    public function canApprove(): bool
    {
        return $this->is_active && $this->role->canApprove();
    }

    public function canManageMembers(): bool
    {
        return $this->is_active && $this->role->canManageMembers();
    }
}
