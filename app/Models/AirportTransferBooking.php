<?php

namespace App\Models;

use App\Contracts\Payments\Payable;
use App\Enums\AirportTransferBookingStatus;
use App\Enums\AirportTransferEventType;
use App\Enums\AirportTransferType;
use App\Models\Concerns\IsPayable;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Cast-backed and mutator-backed attributes declared for static analysis. The
 * schema stores these as strings, so the enum, encrypted, and
 * immutable-datetime casts are otherwise invisible to Larastan.
 *
 * @property AirportTransferBookingStatus $status
 * @property AirportTransferType $transfer_type
 * @property string $reference
 * @property string $airport_code_snapshot
 * @property string $airport_name_snapshot
 * @property string $location_name_snapshot
 * @property string $vehicle_type_snapshot
 * @property string $currency
 * @property int $amount_minor
 * @property int $passenger_count
 * @property int $luggage_count
 * @property int $estimated_duration_minutes
 * @property string|null $flight_number
 * @property string $service_address
 * @property CarbonImmutable $service_starts_at
 * @property CarbonImmutable $service_ends_at
 * @property CarbonImmutable $cancellation_cutoff_at
 * @property CarbonImmutable $request_expires_at
 * @property CarbonImmutable $flight_scheduled_at
 * @property User|null $customer
 */
class AirportTransferBooking extends Model implements Payable
{
    use HasFactory;
    use IsPayable;

    protected $fillable = [
        'reference',
        'customer_id',
        'airport_id',
        'airport_transfer_location_id',
        'airport_transfer_rate_id',
        'idempotency_owner_hash',
        'idempotency_key',
        'request_fingerprint',
        'status',
        'transfer_type',
        'airport_code_snapshot',
        'airport_name_snapshot',
        'location_name_snapshot',
        'vehicle_type_snapshot',
        'passenger_capacity_snapshot',
        'luggage_capacity_snapshot',
        'amount_minor',
        'currency',
        'estimated_duration_minutes',
        'service_starts_at',
        'service_ends_at',
        'cancellation_cutoff_at',
        'request_expires_at',
        'flight_number',
        'flight_scheduled_at',
        'passenger_count',
        'luggage_count',
        'service_address',
        'contact_name',
        'contact_email',
        'contact_phone',
        'special_requests',
        'internal_notes',
        'cancellation_reason',
        'cancelled_by_user_id',
        'assigned_vehicle_id',
        'assigned_driver_user_id',
        'confirmed_at',
        'in_progress_at',
        'completed_at',
        'cancelled_at',
        'declined_at',
        'expired_at',
    ];

    protected $hidden = [
        'idempotency_owner_hash',
        'idempotency_key',
        'request_fingerprint',
        'internal_notes',
        'flight_number',
        'service_address',
    ];

    protected function casts(): array
    {
        return [
            'status' => AirportTransferBookingStatus::class,
            'transfer_type' => AirportTransferType::class,
            'passenger_capacity_snapshot' => 'integer',
            'luggage_capacity_snapshot' => 'integer',
            'amount_minor' => 'integer',
            'estimated_duration_minutes' => 'integer',
            'service_starts_at' => 'immutable_datetime',
            'service_ends_at' => 'immutable_datetime',
            'cancellation_cutoff_at' => 'immutable_datetime',
            'request_expires_at' => 'immutable_datetime',
            'flight_number' => 'encrypted',
            'flight_scheduled_at' => 'immutable_datetime',
            'passenger_count' => 'integer',
            'luggage_count' => 'integer',
            'service_address' => 'encrypted',
            'confirmed_at' => 'immutable_datetime',
            'in_progress_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'declined_at' => 'immutable_datetime',
            'expired_at' => 'immutable_datetime',
        ];
    }

    protected function airportCodeSnapshot(): Attribute
    {
        return Attribute::make(
            set: static fn (mixed $value): string => strtoupper(trim((string) $value)),
        );
    }

    protected function vehicleTypeSnapshot(): Attribute
    {
        return Attribute::make(
            set: static fn (mixed $value): string => strtolower(trim((string) $value)),
        );
    }

    protected function currency(): Attribute
    {
        return Attribute::make(
            set: static fn (mixed $value): string => strtoupper(trim((string) $value)),
        );
    }

    protected function contactEmail(): Attribute
    {
        return Attribute::make(
            set: static fn (mixed $value): string => strtolower(trim((string) $value)),
        );
    }

    public function getRouteKeyName(): string
    {
        return 'reference';
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function airport(): BelongsTo
    {
        return $this->belongsTo(Airport::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(AirportTransferLocation::class, 'airport_transfer_location_id');
    }

    public function rate(): BelongsTo
    {
        return $this->belongsTo(AirportTransferRate::class, 'airport_transfer_rate_id');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }

    public function assignedVehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'assigned_vehicle_id');
    }

    public function assignedDriver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_driver_user_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(AirportTransferAssignment::class)
            ->orderByDesc('assigned_at')
            ->orderByDesc('id');
    }

    public function latestAssignment(): HasOne
    {
        return $this->hasOne(AirportTransferAssignment::class)->latestOfMany('assigned_at');
    }

    public function activeAssignment(): HasOne
    {
        return $this->hasOne(AirportTransferAssignment::class)
            ->whereNull('unassigned_at')
            ->latestOfMany('assigned_at');
    }

    public function events(): HasMany
    {
        return $this->hasMany(AirportTransferEvent::class);
    }

    public function scopeForCustomer(Builder $query, User|int $customer): Builder
    {
        $customerId = $customer instanceof User ? $customer->getKey() : $customer;

        return $query->where('customer_id', $customerId);
    }

    public function scopeForAirport(Builder $query, Airport|int $airport): Builder
    {
        $airportId = $airport instanceof Airport ? $airport->getKey() : $airport;

        return $query->where('airport_id', $airportId);
    }

    public function scopeForLocation(Builder $query, AirportTransferLocation|int $location): Builder
    {
        $locationId = $location instanceof AirportTransferLocation ? $location->getKey() : $location;

        return $query->where('airport_transfer_location_id', $locationId);
    }

    public function scopeWithStatus(Builder $query, AirportTransferBookingStatus $status): Builder
    {
        return $query->where('status', $status->value);
    }

    public function scopeHoldingResources(Builder $query, ?DateTimeInterface $at = null): Builder
    {
        $at ??= now();

        return $query->where(function (Builder $holding) use ($at): void {
            $holding
                ->whereIn('status', [
                    AirportTransferBookingStatus::Confirmed->value,
                    AirportTransferBookingStatus::InProgress->value,
                ])
                ->orWhere(function (Builder $pending) use ($at): void {
                    $pending
                        ->where('status', AirportTransferBookingStatus::Pending->value)
                        ->where('request_expires_at', '>', $at);
                });
        });
    }

    public function scopeOverlapping(
        Builder $query,
        DateTimeInterface $startsAt,
        DateTimeInterface $endsAt,
    ): Builder {
        return $query
            ->where('service_starts_at', '<', $endsAt)
            ->where('service_ends_at', '>', $startsAt);
    }

    public function scopeUpcoming(Builder $query, ?DateTimeInterface $at = null): Builder
    {
        return $query
            ->where('service_starts_at', '>=', $at ?? now())
            ->orderBy('service_starts_at')
            ->orderBy('id');
    }

    public function scopeExpiredRequests(Builder $query, ?DateTimeInterface $at = null): Builder
    {
        return $query
            ->where('status', AirportTransferBookingStatus::Pending->value)
            ->where('request_expires_at', '<=', $at ?? now());
    }

    public function canTransitionTo(AirportTransferBookingStatus $next): bool
    {
        return $this->status->canTransitionTo($next);
    }

    public function holdsResourcesAt(?DateTimeInterface $at = null): bool
    {
        $at ??= now();

        return in_array($this->status, [
            AirportTransferBookingStatus::Confirmed,
            AirportTransferBookingStatus::InProgress,
        ], true) || ($this->status === AirportTransferBookingStatus::Pending
            && $this->request_expires_at->isAfter($at));
    }

    public function canBeCancelledAt(?DateTimeInterface $at = null): bool
    {
        $at ??= now();

        if (! in_array($this->status, [
            AirportTransferBookingStatus::Pending,
            AirportTransferBookingStatus::Confirmed,
        ], true)) {
            return false;
        }

        if ($this->status === AirportTransferBookingStatus::Pending
            && ! $this->request_expires_at->isAfter($at)) {
            return false;
        }

        return $this->cancellation_cutoff_at->isAfter($at);
    }

    public function isGuest(): bool
    {
        return $this->customer_id === null;
    }

    // ---- Payable --------------------------------------------------------

    public function paymentDescription(): string
    {
        return $this->transfer_type->label().' — '.$this->airport_code_snapshot
            .' / '.$this->location_name_snapshot;
    }

    public function payableAmountMinor(): int
    {
        return (int) $this->amount_minor;
    }

    /**
     * Only a live request or a confirmed transfer takes money. A pending
     * request whose review deadline has passed is not payable either, since
     * the team it would have reserved has already been released.
     */
    public function acceptsPayment(): bool
    {
        if ($this->status === AirportTransferBookingStatus::Pending) {
            return $this->request_expires_at->isFuture();
        }

        return $this->status === AirportTransferBookingStatus::Confirmed;
    }

    /**
     * Runs inside the settlement transaction and must be safe to call twice.
     */
    public function applySettledPayment(Payment $payment): void
    {
        if (! $this->isPaidInFull()) {
            return;
        }

        AirportTransferEvent::query()->firstOrCreate(
            [
                'airport_transfer_booking_id' => $this->getKey(),
                'event_type' => AirportTransferEventType::LoyaltyEligible->value,
            ],
            [
                'payload' => [
                    'schema_version' => 1,
                    'booking_id' => $this->getKey(),
                    'booking_reference' => $this->reference,
                    'customer_id' => $this->customer_id,
                    'amount_minor' => (int) $this->amount_minor,
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
        return route('portal.airport-transfer-bookings.show', [
            'customerAirportTransferBooking' => $this->reference,
        ]);
    }
}
