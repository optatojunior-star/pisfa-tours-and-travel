<?php

namespace App\Models;

use App\Enums\PayrollDeductionType;
use App\Support\Money;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line off a payslip.
 *
 * `basis_minor` is kept beside `amount_minor` so the payslip explains itself:
 * what the deduction was charged on, and what came off. Without it, an employee
 * questioning their PAYE has nothing to check against.
 *
 * @property PayrollDeductionType $type
 * @property string $label
 * @property string $currency
 * @property int $basis_minor
 * @property int $amount_minor
 * @property int $sequence
 * @property int $payroll_line_id
 * @property PayrollLine|null $line
 */
class PayrollDeduction extends Model
{
    use HasFactory;

    protected $fillable = [
        'payroll_line_id',
        'type',
        'label',
        'basis_minor',
        'amount_minor',
        'currency',
        'sequence',
    ];

    protected function casts(): array
    {
        return [
            'type' => PayrollDeductionType::class,
            'basis_minor' => 'integer',
            'amount_minor' => 'integer',
            'sequence' => 'integer',
        ];
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(PayrollLine::class, 'payroll_line_id');
    }

    public function formattedAmount(): string
    {
        return Money::format($this->amount_minor, $this->currency);
    }

    public function formattedBasis(): string
    {
        return Money::format($this->basis_minor, $this->currency);
    }
}
