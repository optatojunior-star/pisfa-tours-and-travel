<?php

namespace App\Models;

use App\Enums\PayrollRunStatus;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One month of payroll, in one currency.
 *
 * A run is denominated in a single currency because money is never summed
 * across currencies: a business paying some staff in dollars runs a second
 * payroll rather than mixing the two in one total.
 *
 * @property PayrollRunStatus $status
 * @property string $reference
 * @property string $currency
 * @property int $gross_total_minor
 * @property int $deductions_total_minor
 * @property int $net_total_minor
 * @property int $employer_cost_minor
 * @property int $employee_count
 * @property string|null $payment_reference
 * @property string|null $closure_reason
 * @property CarbonImmutable $period_start
 * @property CarbonImmutable $period_end
 * @property CarbonImmutable|null $approved_at
 * @property CarbonImmutable|null $paid_at
 */
class PayrollRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'reference',
        'status',
        'period_start',
        'period_end',
        'currency',
        'gross_total_minor',
        'deductions_total_minor',
        'net_total_minor',
        'employer_cost_minor',
        'employee_count',
        'approved_at',
        'paid_at',
        'payment_reference',
        'closure_reason',
        'approved_by_user_id',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => PayrollRunStatus::class,
            'period_start' => 'immutable_date',
            'period_end' => 'immutable_date',
            'gross_total_minor' => 'integer',
            'deductions_total_minor' => 'integer',
            'net_total_minor' => 'integer',
            'employer_cost_minor' => 'integer',
            'employee_count' => 'integer',
            'approved_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'reference';
    }

    /** @return HasMany<PayrollLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(PayrollLine::class)->orderBy('employee_name_snapshot');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** A draft is internal working and never reaches an employee. */
    public function scopeVisibleToEmployees(Builder $query): Builder
    {
        return $query->whereIn('status', [
            PayrollRunStatus::Approved->value,
            PayrollRunStatus::Paid->value,
        ]);
    }

    public function canTransitionTo(PayrollRunStatus $next): bool
    {
        return $this->status->canTransitionTo($next);
    }

    public function monthLabel(): string
    {
        return $this->period_start->format('F Y');
    }

    public function formattedGross(): string
    {
        return Money::format($this->gross_total_minor, $this->currency);
    }

    public function formattedDeductions(): string
    {
        return Money::format($this->deductions_total_minor, $this->currency);
    }

    public function formattedNet(): string
    {
        return Money::format($this->net_total_minor, $this->currency);
    }

    /** What the month actually costs the business: gross pay plus employer NSSF. */
    public function formattedEmployerCost(): string
    {
        return Money::format($this->employer_cost_minor, $this->currency);
    }
}
