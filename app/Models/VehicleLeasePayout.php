<?php

namespace App\Models;

use App\Enums\LeasePayoutStatus;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What an owner is owed for one period.
 *
 * The basis is stored beside the result — gross revenue, hire count, the share
 * applied, deductions — so a statement explains itself without re-running the
 * calculation against data that has moved on since.
 *
 * @property LeasePayoutStatus $status
 * @property string $reference
 * @property string $currency
 * @property int $gross_revenue_minor
 * @property int $hire_count
 * @property int|null $revenue_share_bps
 * @property int $earned_minor
 * @property int $deductions_minor
 * @property int $net_payable_minor
 * @property int $excluded_hire_count
 * @property int $vehicle_lease_id
 * @property string|null $deductions_note
 * @property string|null $payment_reference
 * @property string|null $closure_reason
 * @property CarbonImmutable $period_start
 * @property CarbonImmutable $period_end
 * @property CarbonImmutable|null $approved_at
 * @property CarbonImmutable|null $paid_at
 * @property VehicleLease|null $lease
 */
class VehicleLeasePayout extends Model
{
    use HasFactory;

    protected $fillable = [
        'reference',
        'vehicle_lease_id',
        'status',
        'period_start',
        'period_end',
        'gross_revenue_minor',
        'hire_count',
        'revenue_share_bps',
        'earned_minor',
        'deductions_minor',
        'deductions_note',
        'net_payable_minor',
        'currency',
        'excluded_hire_count',
        'approved_at',
        'paid_at',
        'payment_reference',
        'closure_reason',
        'approved_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => LeasePayoutStatus::class,
            'period_start' => 'immutable_date',
            'period_end' => 'immutable_date',
            'gross_revenue_minor' => 'integer',
            'hire_count' => 'integer',
            'revenue_share_bps' => 'integer',
            'earned_minor' => 'integer',
            'deductions_minor' => 'integer',
            'net_payable_minor' => 'integer',
            'excluded_hire_count' => 'integer',
            'approved_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'reference';
    }

    public function lease(): BelongsTo
    {
        return $this->belongsTo(VehicleLease::class, 'vehicle_lease_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    /** A draft is internal working and never reaches the owner. */
    public function scopeVisibleToOwner(Builder $query): Builder
    {
        return $query->whereIn('status', [
            LeasePayoutStatus::Approved->value,
            LeasePayoutStatus::Paid->value,
            LeasePayoutStatus::Cancelled->value,
        ]);
    }

    public function canTransitionTo(LeasePayoutStatus $next): bool
    {
        return $this->status->canTransitionTo($next);
    }

    public function periodLabel(): string
    {
        return $this->period_start->format('j M Y').' — '.$this->period_end->format('j M Y');
    }

    public function monthLabel(): string
    {
        return $this->period_start->format('F Y');
    }

    public function formattedGrossRevenue(): string
    {
        return Money::format($this->gross_revenue_minor, $this->currency);
    }

    public function formattedEarned(): string
    {
        return Money::format($this->earned_minor, $this->currency);
    }

    public function formattedDeductions(): string
    {
        return Money::format($this->deductions_minor, $this->currency);
    }

    public function formattedNet(): string
    {
        return Money::format($this->net_payable_minor, $this->currency);
    }
}
