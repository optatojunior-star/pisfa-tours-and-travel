<?php

namespace App\Models;

use App\Enums\DocumentCategory;
use App\Enums\LeasePayoutModel;
use App\Enums\LeaseStatus;
use App\Support\Money;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * An agreement to run somebody else's vehicle in PISFA's hire fleet.
 *
 * The lease and the fleet are one fact: activating it puts the vehicle in the
 * fleet, ending it takes the vehicle out. A car that stayed bookable after its
 * lease ended would have PISFA hiring out something it no longer controls.
 *
 * @property LeaseStatus $status
 * @property LeasePayoutModel $payout_model
 * @property string $reference
 * @property string $currency
 * @property int $owner_id
 * @property int|null $vehicle_id
 * @property int|null $application_id
 * @property int|null $monthly_retainer_minor
 * @property int|null $revenue_share_bps
 * @property int $notice_period_days
 * @property string|null $terms
 * @property string|null $internal_notes
 * @property string|null $suspension_reason
 * @property string|null $termination_reason
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable|null $ends_on
 * @property CarbonImmutable|null $activated_at
 * @property CarbonImmutable|null $ended_at
 * @property User|null $owner
 * @property Vehicle|null $vehicle
 * @property VehicleLeaseApplication|null $application
 */
class VehicleLease extends Model
{
    use HasFactory;

    protected $fillable = [
        'reference',
        'owner_id',
        'vehicle_id',
        'application_id',
        'status',
        'payout_model',
        'monthly_retainer_minor',
        'revenue_share_bps',
        'currency',
        'starts_on',
        'ends_on',
        'notice_period_days',
        'terms',
        'internal_notes',
        'activated_at',
        'suspended_at',
        'suspension_reason',
        'ended_at',
        'termination_reason',
        'created_by_user_id',
    ];

    protected $hidden = ['internal_notes'];

    protected function casts(): array
    {
        return [
            'status' => LeaseStatus::class,
            'payout_model' => LeasePayoutModel::class,
            'monthly_retainer_minor' => 'integer',
            'revenue_share_bps' => 'integer',
            'notice_period_days' => 'integer',
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'activated_at' => 'immutable_datetime',
            'suspended_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'reference';
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(VehicleLeaseApplication::class, 'application_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return HasMany<VehicleLeasePayout, $this> */
    public function payouts(): HasMany
    {
        return $this->hasMany(VehicleLeasePayout::class)->orderByDesc('period_start');
    }

    /** @return MorphMany<Document, $this> */
    public function agreements(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable')
            ->where('category', DocumentCategory::LeaseContract->value);
    }

    public function scopeForOwner(Builder $query, User|int $owner): Builder
    {
        return $query->where('owner_id', $owner instanceof User ? $owner->getKey() : $owner);
    }

    public function scopeRunning(Builder $query): Builder
    {
        return $query->whereIn('status', LeaseStatus::runningValues());
    }

    public function scopeSearch(Builder $query, string $search): Builder
    {
        $search = trim($search);

        if ($search === '') {
            return $query;
        }

        return $query->where('reference', 'like', '%'.$search.'%');
    }

    public function canTransitionTo(LeaseStatus $next): bool
    {
        return $this->status->canTransitionTo($next);
    }

    /** Whether the agreement covers the given calendar date. */
    public function coversDate(DateTimeInterface|string $date): bool
    {
        $day = CarbonImmutable::parse($date)->startOfDay();

        if ($day->isBefore($this->starts_on->startOfDay())) {
            return false;
        }

        return $this->ends_on === null || ! $day->isAfter($this->ends_on->startOfDay());
    }

    public function formattedRetainer(): ?string
    {
        return $this->monthly_retainer_minor === null
            ? null
            : Money::format($this->monthly_retainer_minor, $this->currency);
    }

    /** The share as a percentage string, from the stored basis points. */
    public function formattedShare(): ?string
    {
        if ($this->revenue_share_bps === null) {
            return null;
        }

        return rtrim(rtrim(number_format($this->revenue_share_bps / 100, 2), '0'), '.').'%';
    }

    public function termsSummary(): string
    {
        return match ($this->payout_model) {
            LeasePayoutModel::FixedMonthly => ($this->formattedRetainer() ?? '—').' a month',
            LeasePayoutModel::RevenueShare => ($this->formattedShare() ?? '—').' of hire revenue',
        };
    }
}
