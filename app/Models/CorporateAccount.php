<?php

namespace App\Models;

use App\Enums\CorporateAccountStatus;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A company that travels with PISFA on agreed terms.
 *
 * The outstanding balance is deliberately not a column. A stored figure drifts
 * the moment an invoice is voided, a payment lands out of band, or two requests
 * update it at once — and a credit limit checked against a drifted balance is
 * worse than no limit at all. It is always computed from live invoices, by
 * CorporateCreditQuery.
 *
 * @property CorporateAccountStatus $status
 * @property string $slug
 * @property string $name
 * @property string $currency
 * @property int $credit_limit_minor
 * @property int $payment_terms_days
 * @property int $discount_bps
 * @property string $billing_contact_name
 * @property string $billing_contact_email
 * @property string $billing_contact_phone
 * @property string|null $internal_notes
 * @property string|null $suspension_reason
 * @property string|null $closure_reason
 * @property CarbonImmutable|null $activated_at
 */
class CorporateAccount extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'slug',
        'name',
        'registration_number',
        'tax_identification_number',
        'industry',
        'billing_contact_name',
        'billing_contact_email',
        'billing_contact_phone',
        'billing_address',
        'status',
        'payment_terms_days',
        'credit_limit_minor',
        'currency',
        'discount_bps',
        'notes',
        'internal_notes',
        'activated_at',
        'suspended_at',
        'suspension_reason',
        'closed_at',
        'closure_reason',
        'created_by_user_id',
    ];

    protected $hidden = ['internal_notes'];

    protected function casts(): array
    {
        return [
            'status' => CorporateAccountStatus::class,
            'payment_terms_days' => 'integer',
            'credit_limit_minor' => 'integer',
            'discount_bps' => 'integer',
            'activated_at' => 'immutable_datetime',
            'suspended_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
        ];
    }

    protected function billingContactEmail(): Attribute
    {
        return Attribute::make(set: static fn (mixed $v): string => mb_strtolower(trim((string) $v)));
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** @return HasMany<CorporateMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(CorporateMember::class)->orderBy('id');
    }

    /** @return HasMany<CorporateMember, $this> */
    public function activeMembers(): HasMany
    {
        return $this->members()->where('is_active', true);
    }

    /** @return HasMany<GroupBooking, $this> */
    public function groupBookings(): HasMany
    {
        return $this->hasMany(GroupBooking::class)->latest('starts_on');
    }

    /** @return HasMany<Invoice, $this> */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class)->latest('id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function scopeTrading(Builder $query): Builder
    {
        return $query->where('status', CorporateAccountStatus::Active->value);
    }

    public function scopeSearch(Builder $query, string $search): Builder
    {
        $search = trim($search);

        if ($search === '') {
            return $query;
        }

        return $query->where(function (Builder $nested) use ($search): void {
            $nested->where('name', 'like', '%'.$search.'%')
                ->orWhere('billing_contact_email', 'like', '%'.$search.'%')
                ->orWhere('registration_number', 'like', '%'.$search.'%');
        });
    }

    public function canTransitionTo(CorporateAccountStatus $next): bool
    {
        return $this->status->canTransitionTo($next);
    }

    public function hasCredit(): bool
    {
        return $this->credit_limit_minor > 0;
    }

    public function formattedCreditLimit(): string
    {
        return Money::format($this->credit_limit_minor, $this->currency);
    }

    /** The agreed discount as a percentage string, from the stored basis points. */
    public function formattedDiscount(): ?string
    {
        if ($this->discount_bps < 1) {
            return null;
        }

        return rtrim(rtrim(number_format($this->discount_bps / 100, 2), '0'), '.').'%';
    }

    public function termsSummary(): string
    {
        $parts = ['Net '.$this->payment_terms_days.' days'];

        if ($this->hasCredit()) {
            $parts[] = $this->formattedCreditLimit().' limit';
        }

        if ($this->formattedDiscount() !== null) {
            $parts[] = $this->formattedDiscount().' discount';
        }

        return implode(' · ', $parts);
    }
}
