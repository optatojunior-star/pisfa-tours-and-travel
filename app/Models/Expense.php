<?php

namespace App\Models;

use App\Enums\DocumentCategory;
use App\Enums\ExpenseCategory;
use App\Enums\ExpenseStatus;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Money the business spent, or somebody spent on its behalf.
 *
 * @property ExpenseStatus $status
 * @property ExpenseCategory $category
 * @property string $reference
 * @property string $currency
 * @property int $amount_minor
 * @property int $incurred_by_user_id
 * @property int|null $vehicle_id
 * @property int|null $recovered_on_payout_id
 * @property bool $is_recoverable
 * @property string $description
 * @property string|null $supplier
 * @property string|null $internal_notes
 * @property string|null $reimbursement_reference
 * @property string|null $closure_reason
 * @property CarbonImmutable $spent_on
 * @property CarbonImmutable|null $approved_at
 * @property CarbonImmutable|null $reimbursed_at
 * @property CarbonImmutable $created_at
 * @property User|null $incurredBy
 * @property Vehicle|null $vehicle
 */
class Expense extends Model
{
    use HasFactory;

    protected $fillable = [
        'reference',
        'incurred_by_user_id',
        'approved_by_user_id',
        'vehicle_id',
        'status',
        'category',
        'spent_on',
        'amount_minor',
        'currency',
        'description',
        'supplier',
        'internal_notes',
        'is_recoverable',
        'recovered_on_payout_id',
        'submitted_at',
        'approved_at',
        'reimbursed_at',
        'reimbursement_reference',
        'closure_reason',
    ];

    protected $hidden = ['internal_notes'];

    protected function casts(): array
    {
        return [
            'status' => ExpenseStatus::class,
            'category' => ExpenseCategory::class,
            'spent_on' => 'immutable_date',
            'amount_minor' => 'integer',
            'is_recoverable' => 'boolean',
            'submitted_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'reimbursed_at' => 'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'reference';
    }

    public function incurredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'incurred_by_user_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function recoveredOnPayout(): BelongsTo
    {
        return $this->belongsTo(VehicleLeasePayout::class, 'recovered_on_payout_id');
    }

    /** @return MorphMany<Document, $this> */
    public function receipts(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable')
            ->where('category', DocumentCategory::ExpenseReceipt->value);
    }

    public function scopeForUser(Builder $query, User|int $user): Builder
    {
        return $query->where('incurred_by_user_id', $user instanceof User ? $user->getKey() : $user);
    }

    /** Only spending that has actually been approved counts as spend. */
    public function scopeCountedAsSpend(Builder $query): Builder
    {
        return $query->whereIn('status', ExpenseStatus::spendValues());
    }

    /**
     * Approved, vehicle-attached spending that has not yet been recovered.
     *
     * The null check on `recovered_on_payout_id` is what stops the same repair
     * being deducted from an owner twice.
     */
    public function scopeAwaitingRecovery(Builder $query): Builder
    {
        return $query->whereIn('status', ExpenseStatus::spendValues())
            ->where('is_recoverable', true)
            ->whereNotNull('vehicle_id')
            ->whereNull('recovered_on_payout_id');
    }

    public function scopeSearch(Builder $query, string $search): Builder
    {
        $search = trim($search);

        if ($search === '') {
            return $query;
        }

        return $query->where(function (Builder $nested) use ($search): void {
            $nested->where('reference', 'like', '%'.$search.'%')
                ->orWhere('description', 'like', '%'.$search.'%')
                ->orWhere('supplier', 'like', '%'.$search.'%');
        });
    }

    public function canTransitionTo(ExpenseStatus $next): bool
    {
        return $this->status->canTransitionTo($next);
    }

    public function hasBeenRecovered(): bool
    {
        return $this->recovered_on_payout_id !== null;
    }

    public function formattedAmount(): string
    {
        return Money::format($this->amount_minor, $this->currency);
    }
}
