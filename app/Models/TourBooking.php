<?php

namespace App\Models;

use App\Contracts\Payments\Payable;
use App\Enums\TourBookingEventType;
use App\Enums\TourBookingStatus;
use App\Models\Concerns\IsPayable;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * @property TourBookingStatus $status
 * @property int $total_minor
 * @property int $subtotal_minor
 * @property string $currency
 * @property string $reference
 * @property string $package_name_snapshot
 * @property int|null $customer_id
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable $departure_starts_at_snapshot
 * @property User|null $customer
 * @property TourDeparture|null $departure
 * @property Review|null $review
 */
class TourBooking extends Model implements Payable
{
    use HasFactory;
    use IsPayable;

    protected $fillable = [
        'reference',
        'customer_id',
        'tour_package_id',
        'tour_departure_id',
        'idempotency_key',
        'status',
        'traveler_count',
        'package_name_snapshot',
        'destination_snapshot',
        'departure_starts_at_snapshot',
        'departure_ends_at_snapshot',
        'cancellation_cutoff_at_snapshot',
        'unit_price_minor',
        'subtotal_minor',
        'total_minor',
        'currency',
        'contact_name',
        'contact_email',
        'contact_phone',
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
        'internal_notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => TourBookingStatus::class,
            'traveler_count' => 'integer',
            'departure_starts_at_snapshot' => 'immutable_datetime',
            'departure_ends_at_snapshot' => 'immutable_datetime',
            'cancellation_cutoff_at_snapshot' => 'immutable_datetime',
            'unit_price_minor' => 'integer',
            'subtotal_minor' => 'integer',
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

    public function tourPackage(): BelongsTo
    {
        return $this->belongsTo(TourPackage::class);
    }

    public function departure(): BelongsTo
    {
        return $this->belongsTo(TourDeparture::class, 'tour_departure_id');
    }

    public function assignedDriver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_driver_user_id');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }

    public function travelers(): HasMany
    {
        return $this->hasMany(TourTraveler::class)
            ->orderByDesc('is_lead')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(TourAssignment::class)
            ->latest('assigned_at');
    }

    public function events(): HasMany
    {
        return $this->hasMany(TourBookingEvent::class);
    }

    public function scopeForCustomer(Builder $query, User|int $customer): Builder
    {
        $customerId = $customer instanceof User ? $customer->getKey() : $customer;

        return $query->where('customer_id', $customerId);
    }

    public function scopeHoldingCapacity(Builder $query): Builder
    {
        return $query->whereIn('status', TourBookingStatus::capacityHoldingValues());
    }

    public function scopeWithStatus(Builder $query, TourBookingStatus $status): Builder
    {
        return $query->where('status', $status->value);
    }

    public function canTransitionTo(TourBookingStatus $next): bool
    {
        return $this->status->canTransitionTo($next);
    }

    public function canBeCancelledAt(?DateTimeInterface $at = null): bool
    {
        $at ??= now();

        return in_array($this->status, [TourBookingStatus::Pending, TourBookingStatus::Confirmed], true)
            && $at < $this->cancellation_cutoff_at_snapshot;
    }

    public function review(): MorphOne
    {
        return $this->morphOne(Review::class, 'booking');
    }

    // ---- Payable --------------------------------------------------------

    public function paymentDescription(): string
    {
        return $this->package_name_snapshot.' — '.$this->traveler_count.' traveller(s)';
    }

    public function payableAmountMinor(): int
    {
        return (int) $this->total_minor;
    }

    /**
     * A cancelled or completed booking never accepts new money, and a departure
     * that has already left cannot be paid for online.
     */
    public function acceptsPayment(): bool
    {
        return in_array($this->status, [
            TourBookingStatus::Pending,
            TourBookingStatus::Confirmed,
        ], true);
    }

    /**
     * Called inside the settlement transaction. Safe to call twice: the
     * loyalty-eligibility marker uses firstOrCreate, so a duplicate webhook or
     * a provider retry cannot award points a second time.
     */
    public function applySettledPayment(Payment $payment): void
    {
        if (! $this->isPaidInFull()) {
            return;
        }

        TourBookingEvent::query()->firstOrCreate(
            [
                'tour_booking_id' => $this->getKey(),
                'event_type' => TourBookingEventType::LoyaltyEligible->value,
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
        return route('portal.bookings.show', ['customerTourBooking' => $this->reference]);
    }
}
