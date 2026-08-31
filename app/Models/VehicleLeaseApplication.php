<?php

namespace App\Models;

use App\Enums\LeaseApplicationStatus;
use App\Enums\LeasePayoutModel;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * An owner offering their vehicle to PISFA's hire fleet.
 *
 * May be raised by a guest, so `owner_id` is nullable throughout — the form is
 * the first contact PISFA has with most owners, and demanding a registration
 * before it would lose the lead. The lease itself requires an account, because
 * that is where payouts are addressed.
 *
 * @property LeaseApplicationStatus $status
 * @property LeasePayoutModel|null $preferred_payout_model
 * @property string $reference
 * @property string $contact_name
 * @property string $contact_email
 * @property string $contact_phone
 * @property string $registration_plate
 * @property int|null $owner_id
 * @property int|null $assigned_to_user_id
 * @property int|null $expected_monthly_minor
 * @property string|null $expected_currency
 * @property string|null $internal_notes
 * @property string|null $inspection_findings
 * @property string|null $closure_reason
 * @property CarbonImmutable|null $available_from
 * @property CarbonImmutable|null $inspection_at
 * @property CarbonImmutable|null $closed_at
 * @property CarbonImmutable $created_at
 * @property User|null $owner
 * @property User|null $assignee
 * @property VehicleLease|null $lease
 */
class VehicleLeaseApplication extends Model
{
    use HasFactory;

    protected $fillable = [
        'reference',
        'owner_id',
        'assigned_to_user_id',
        'status',
        'contact_name',
        'contact_email',
        'contact_phone',
        'make',
        'model',
        'year',
        'registration_plate',
        'colour',
        'transmission',
        'fuel_type',
        'seating_capacity',
        'mileage_km',
        'condition',
        'preferred_payout_model',
        'expected_monthly_minor',
        'expected_currency',
        'available_from',
        'notes',
        'internal_notes',
        'inspection_at',
        'inspection_location',
        'inspection_findings',
        'closure_reason',
        'closed_at',
        'idempotency_owner_hash',
        'idempotency_key',
    ];

    /**
     * The registration plate is hidden alongside the idempotency material.
     *
     * It identifies somebody's property and is the same value the fleet treats
     * as sensitive; there is no surface that needs it serialised.
     */
    protected $hidden = [
        'idempotency_owner_hash',
        'idempotency_key',
        'internal_notes',
        'registration_plate',
    ];

    protected function casts(): array
    {
        return [
            'status' => LeaseApplicationStatus::class,
            'preferred_payout_model' => LeasePayoutModel::class,
            'year' => 'integer',
            'seating_capacity' => 'integer',
            'mileage_km' => 'integer',
            'expected_monthly_minor' => 'integer',
            'available_from' => 'immutable_date',
            'inspection_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
        ];
    }

    protected function contactEmail(): Attribute
    {
        return Attribute::make(set: static fn (mixed $v): string => mb_strtolower(trim((string) $v)));
    }

    protected function registrationPlate(): Attribute
    {
        return Attribute::make(set: static fn (mixed $v): string => mb_strtoupper(trim((string) $v)));
    }

    public function getRouteKeyName(): string
    {
        return 'reference';
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    /** @return HasOne<VehicleLease, $this> */
    public function lease(): HasOne
    {
        return $this->hasOne(VehicleLease::class, 'application_id');
    }

    public function scopeForOwner(Builder $query, User|int $owner): Builder
    {
        return $query->where('owner_id', $owner instanceof User ? $owner->getKey() : $owner);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', LeaseApplicationStatus::openValues());
    }

    public function scopeSearch(Builder $query, string $search): Builder
    {
        $search = trim($search);

        if ($search === '') {
            return $query;
        }

        return $query->where(function (Builder $nested) use ($search): void {
            $nested->where('reference', 'like', '%'.$search.'%')
                ->orWhere('contact_name', 'like', '%'.$search.'%')
                ->orWhere('contact_email', 'like', '%'.$search.'%')
                ->orWhere('registration_plate', 'like', '%'.$search.'%');
        });
    }

    public function isGuest(): bool
    {
        return $this->owner_id === null;
    }

    public function canTransitionTo(LeaseApplicationStatus $next): bool
    {
        return $this->status->canTransitionTo($next);
    }

    public function vehicleLabel(): string
    {
        return trim($this->year.' '.$this->make.' '.$this->model);
    }

    public function formattedExpectation(): ?string
    {
        if ($this->expected_monthly_minor === null || $this->expected_currency === null) {
            return null;
        }

        return Money::format($this->expected_monthly_minor, $this->expected_currency);
    }
}
