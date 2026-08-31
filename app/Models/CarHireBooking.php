<?php

namespace App\Models;

use App\Contracts\Payments\Payable;
use App\Enums\CarHireBookingEventType;
use App\Enums\CarHireBookingStatus;
use App\Enums\HireMode;
use App\Models\Concerns\IsPayable;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Cast-backed attributes declared for static analysis. The schema stores these
 * as strings and datetimes, so the enum and immutable-datetime casts are
 * otherwise invisible to Larastan.
 *
 * @property CarHireBookingStatus $status
 * @property HireMode $hire_mode
 * @property CarbonImmutable $pickup_at
 * @property CarbonImmutable $return_at
 * @property CarbonImmutable $hold_expires_at
 * @property CarbonImmutable|null $confirmed_at
 * @property CarbonImmutable|null $in_progress_at
 * @property CarbonImmutable|null $completed_at
 * @property int $daily_rate_minor
 * @property int $rental_subtotal_minor
 * @property int $security_deposit_minor
 * @property int $total_minor
 * @property int $billable_days
 * @property User|null $customer
 */
class CarHireBooking extends Model implements Payable
{
    use HasFactory;
    use IsPayable;

    protected $fillable = [
        'reference',
        'customer_id',
        'vehicle_id',
        'vehicle_hire_rate_id',
        'idempotency_key',
        'request_fingerprint',
        'status',
        'hire_mode',
        'pickup_at',
        'return_at',
        'cancellation_cutoff_at',
        'hold_expires_at',
        'billable_days',
        'vehicle_name_snapshot',
        'registration_plate_snapshot',
        'daily_rate_minor',
        'rental_subtotal_minor',
        'security_deposit_minor',
        'total_minor',
        'currency',
        'contact_name',
        'contact_email',
        'contact_phone',
        'pickup_location',
        'return_location',
        'special_requests',
        'internal_notes',
        'cancellation_reason',
        'cancelled_by_user_id',
        'confirmed_at',
        'in_progress_at',
        'completed_at',
        'cancelled_at',
        'assigned_driver_user_id',
    ];

    protected $hidden = [
        'idempotency_key',
        'request_fingerprint',
        'internal_notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => CarHireBookingStatus::class,
            'hire_mode' => HireMode::class,
            'pickup_at' => 'immutable_datetime',
            'return_at' => 'immutable_datetime',
            'cancellation_cutoff_at' => 'immutable_datetime',
            'hold_expires_at' => 'immutable_datetime',
            'billable_days' => 'integer',
            'daily_rate_minor' => 'integer',
            'rental_subtotal_minor' => 'integer',
            'security_deposit_minor' => 'integer',
            'total_minor' => 'integer',
            'confirmed_at' => 'immutable_datetime',
            'in_progress_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'reference';
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function hireRate(): BelongsTo
    {
        return $this->belongsTo(VehicleHireRate::class, 'vehicle_hire_rate_id');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }

    public function assignedDriver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_driver_user_id');
    }

    public function selfDriveApplication(): HasOne
    {
        return $this->hasOne(CarHireSelfDriveApplication::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(CarHireDocument::class)
            ->orderBy('document_type')
            ->orderBy('id');
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(CarHireContract::class)
            ->orderByDesc('version')
            ->orderByDesc('id');
    }

    public function latestContract(): HasOne
    {
        return $this->hasOne(CarHireContract::class)->ofMany('version', 'max');
    }

    public function latestNonVoidedContract(): HasOne
    {
        return $this->hasOne(CarHireContract::class)->ofMany(
            ['version' => 'max'],
            fn (Builder $query): Builder => $query->whereNull('voided_at'),
        );
    }

    public function driverAssignments(): HasMany
    {
        return $this->hasMany(CarHireDriverAssignment::class)
            ->orderByDesc('assigned_at')
            ->orderByDesc('id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(CarHireBookingEvent::class);
    }

    public function scopeForCustomer(Builder $query, User|int $customer): Builder
    {
        $customerId = $customer instanceof User ? $customer->getKey() : $customer;

        return $query->where('customer_id', $customerId);
    }

    public function scopeForVehicle(Builder $query, Vehicle|int $vehicle): Builder
    {
        $vehicleId = $vehicle instanceof Vehicle ? $vehicle->getKey() : $vehicle;

        return $query->where('vehicle_id', $vehicleId);
    }

    public function scopeWithStatus(Builder $query, CarHireBookingStatus $status): Builder
    {
        return $query->where('status', $status->value);
    }

    public function scopeHoldingVehicle(Builder $query, ?DateTimeInterface $at = null): Builder
    {
        $at ??= now();

        return $query->where(function (Builder $holding) use ($at): void {
            $holding
                ->whereIn('status', [
                    CarHireBookingStatus::Confirmed->value,
                    CarHireBookingStatus::InProgress->value,
                ])
                ->orWhere(function (Builder $pending) use ($at): void {
                    $pending
                        ->where('status', CarHireBookingStatus::Pending->value)
                        ->where('hold_expires_at', '>', $at);
                });
        });
    }

    public function scopeOverlapping(
        Builder $query,
        DateTimeInterface $startsAt,
        DateTimeInterface $endsAt,
    ): Builder {
        return $query
            ->where('pickup_at', '<', $endsAt)
            ->where('return_at', '>', $startsAt);
    }

    public function canTransitionTo(CarHireBookingStatus $next): bool
    {
        return $this->status->canTransitionTo($next);
    }

    public function holdsVehicleAt(?DateTimeInterface $at = null): bool
    {
        $at ??= now();

        return in_array($this->status, [
            CarHireBookingStatus::Confirmed,
            CarHireBookingStatus::InProgress,
        ], true) || ($this->status === CarHireBookingStatus::Pending
            && $this->hold_expires_at->isAfter($at));
    }

    public function canBeCancelledAt(?DateTimeInterface $at = null): bool
    {
        $at ??= now();

        if (! in_array($this->status, [CarHireBookingStatus::Pending, CarHireBookingStatus::Confirmed], true)) {
            return false;
        }

        if ($this->status === CarHireBookingStatus::Pending && ! $this->hold_expires_at->isAfter($at)) {
            return false;
        }

        return $this->cancellation_cutoff_at->isAfter($at);
    }

    // ---- Payable --------------------------------------------------------

    public function paymentDescription(): string
    {
        return $this->vehicle_name_snapshot.' — '.$this->hire_mode->label()
            .', '.$this->billable_days.' day(s)';
    }

    public function payableAmountMinor(): int
    {
        return (int) $this->total_minor;
    }

    /**
     * A hire accepts payment while it is still pending review or confirmed.
     * Declined, expired, cancelled, and completed hires never do.
     */
    public function acceptsPayment(): bool
    {
        return in_array($this->status, [
            CarHireBookingStatus::Pending,
            CarHireBookingStatus::Confirmed,
        ], true);
    }

    /**
     * Runs inside the settlement transaction and must be safe to call twice:
     * the loyalty marker uses firstOrCreate so a duplicate webhook or provider
     * retry cannot award points again.
     */
    public function applySettledPayment(Payment $payment): void
    {
        if (! $this->isPaidInFull()) {
            return;
        }

        CarHireBookingEvent::query()->firstOrCreate(
            [
                'car_hire_booking_id' => $this->getKey(),
                'event_type' => CarHireBookingEventType::LoyaltyEligible->value,
            ],
            [
                'payload' => [
                    'schema_version' => 1,
                    'booking_id' => $this->getKey(),
                    'booking_reference' => $this->reference,
                    'customer_id' => $this->customer_id,
                    'total_minor' => (int) $this->total_minor,
                    'currency' => $this->currency,
                    'payment_reference' => $payment->reference,
                    'settled_at' => now()->toIso8601String(),
                ],
                'processed_at' => null,
            ],
        );
    }

    public function paymentReturnUrl(): string
    {
        return route('portal.car-hire-bookings.show', ['customerCarHireBooking' => $this->reference]);
    }
}
