<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One person's pay for one month.
 *
 * Name and role are snapshotted so a payslip stays truthful after somebody is
 * promoted, renamed, or leaves.
 *
 * @property int $gross_minor
 * @property int $allowances_minor
 * @property int $deductions_minor
 * @property int $net_minor
 * @property int $employer_nssf_minor
 * @property string $currency
 * @property string $employee_name_snapshot
 * @property UserRole $role_snapshot
 * @property int $payroll_run_id
 * @property int $user_id
 * @property string|null $notes
 * @property PayrollRun|null $run
 * @property User|null $employee
 */
class PayrollLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'payroll_run_id',
        'user_id',
        'employee_name_snapshot',
        'role_snapshot',
        'gross_minor',
        'allowances_minor',
        'deductions_minor',
        'net_minor',
        'employer_nssf_minor',
        'currency',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'role_snapshot' => UserRole::class,
            'gross_minor' => 'integer',
            'allowances_minor' => 'integer',
            'deductions_minor' => 'integer',
            'net_minor' => 'integer',
            'employer_nssf_minor' => 'integer',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return HasMany<PayrollDeduction, $this> */
    public function deductions(): HasMany
    {
        return $this->hasMany(PayrollDeduction::class)->orderBy('sequence')->orderBy('id');
    }

    public function scopeForEmployee(Builder $query, User|int $employee): Builder
    {
        return $query->where('user_id', $employee instanceof User ? $employee->getKey() : $employee);
    }

    /** Gross plus allowances: what the deductions are worked out from. */
    public function totalEarningsMinor(): int
    {
        return $this->gross_minor + $this->allowances_minor;
    }

    public function formattedGross(): string
    {
        return Money::format($this->gross_minor, $this->currency);
    }

    public function formattedAllowances(): string
    {
        return Money::format($this->allowances_minor, $this->currency);
    }

    public function formattedEarnings(): string
    {
        return Money::format($this->totalEarningsMinor(), $this->currency);
    }

    public function formattedDeductions(): string
    {
        return Money::format($this->deductions_minor, $this->currency);
    }

    public function formattedNet(): string
    {
        return Money::format($this->net_minor, $this->currency);
    }

    public function formattedEmployerNssf(): string
    {
        return Money::format($this->employer_nssf_minor, $this->currency);
    }
}
