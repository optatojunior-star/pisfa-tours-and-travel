<?php

namespace App\Models;

use App\Contracts\Payments\Payable;
use App\Enums\PropertyBookingEventType;
use App\Enums\PropertyBookingStatus;
use App\Models\Concerns\IsPayable;
use App\Support\Money;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A stay: N rooms of one type, for a run of nights.
 *
 * Every figure the guest was shown is snapshotted, including the property's
 * check-in and check-out times, so a booking remains an accurate record of what
 * was agreed after the property is edited or a season is re-priced.
 *
 * @property PropertyBookingStatus $status
 * @property string $reference
 * @property string $currency
 * @property int $nightly_rate_minor
 * @property int $total_minor
 * @property int $paid_minor
 * @property int $nights
 * @property int $rooms
 * @property int $adults
 * @property int $children
 * @property int $customer_id
 * @property int $property_id
 * @property int $property_room_type_id
 * @property int|null $property_room_rate_id
 * @property string $property_name_snapshot
 * @property string $room_type_name_snapshot
 * @property string $contact_name
 * @property string $contact_email
 * @property string $contact_phone
 * @property string|null $internal_notes
 * @property string|null $closure_reason
 * @property CarbonImmutable $check_in_date
 * @property CarbonImmutable $check_out_date
 * @property CarbonImmutable|null $hold_expires_at
 * @property CarbonImmutable|null $cancellation_cutoff_at
 * @property CarbonImmutable|null $confirmed_at
 * @property CarbonImmutable|null $cancelled_at
 * @property CarbonImmutable $created_at
 * @property User|null $customer
 * @property Property|null $property
 * @property PropertyRoomType|null $roomType
 */
class PropertyBooking extends Model implements Payable
{
    use HasFactory;
    use IsPayable;

    protected $fillable = [
        'reference',
        'customer_id',
        'property_id',
        'property_room_type_id',
        'property_room_rate_id',
        'idempotency_key',
        'request_fingerprint',
        'status',
        'check_in_date',
        'check_out_date',
        'nights',
        'rooms',
        'adults',
        'children',
        'property_name_snapshot',
        'room_type_name_snapshot',
        'check_in_from_snapshot',
        'check_out_by_snapshot',
        'nightly_rate_minor',
        'total_minor',
        'currency',
        'paid_minor',
        'contact_name',
        'contact_email',
        'contact_phone',
        'special_requests',
        'internal_notes',
        'hold_expires_at',
        'cancellation_cutoff_at',
        'confirmed_at',
        'checked_in_at',
        'checked_out_at',
        'cancelled_at',
        'closure_reason',
    ];

    /** Replay material and staff notes are never serialised. */
    protected $hidden = [
        'idempotency_key',
        'request_fingerprint',
        'internal_notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => PropertyBookingStatus::class,
            'check_in_date' => 'immutable_date',
            'check_out_date' => 'immutable_date',
            'nights' => 'integer',
            'rooms' => 'integer',
            'adults' => 'integer',
            'children' => 'integer',
            'nightly_rate_minor' => 'integer',
            'total_minor' => 'integer',
            'paid_minor' => 'integer',
            'hold_expires_at' => 'immutable_datetime',
            'cancellation_cutoff_at' => 'immutable_datetime',
            'confirmed_at' => 'immutable_datetime',
            'checked_in_at' => 'immutable_datetime',
            'checked_out_at' => 'immutable_datetime',
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

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function roomType(): BelongsTo
    {
        return $this->belongsTo(PropertyRoomType::class, 'property_room_type_id');
    }

    public function rate(): BelongsTo
    {
        return $this->belongsTo(PropertyRoomRate::class, 'property_room_rate_id');
    }

    /** @return HasMany<PropertyBookingEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(PropertyBookingEvent::class);
    }

    public function scopeForCustomer(Builder $query, User|int $customer): Builder
    {
        return $query->where('customer_id', $customer instanceof User ? $customer->getKey() : $customer);
    }

    /**
     * Bookings that still occupy a room.
     *
     * A pending booking only holds its rooms while its hold is unexpired: the
     * sweep that expires stale holds may not have run yet, and availability
     * must not depend on a scheduled job having fired.
     */
    public function scopeHoldingInventory(Builder $query, ?DateTimeInterface $at = null): Builder
    {
        $at ??= now();

        return $query->where(function (Builder $holding) use ($at): void {
            $holding
                ->whereIn('status', [
                    PropertyBookingStatus::Confirmed->value,
                    PropertyBookingStatus::CheckedIn->value,
                ])
                ->orWhere(function (Builder $pending) use ($at): void {
                    $pending
                        ->where('status', PropertyBookingStatus::Pending->value)
                        ->where('hold_expires_at', '>', $at);
                });
        });
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
                ->orWhere('property_name_snapshot', 'like', '%'.$search.'%');
        });
    }

    /** Whether this stay occupies the given night. Half-open, so checkout day is free. */
    public function occupiesNight(DateTimeInterface|string $night): bool
    {
        $night = CarbonImmutable::parse($night)->startOfDay();

        return ! $night->isBefore($this->check_in_date->startOfDay())
            && $night->isBefore($this->check_out_date->startOfDay());
    }

    public function canTransitionTo(PropertyBookingStatus $next): bool
    {
        return $this->status->canTransitionTo($next);
    }

    public function holdHasExpired(?DateTimeInterface $at = null): bool
    {
        return $this->status === PropertyBookingStatus::Pending
            && $this->hold_expires_at !== null
            && ! $this->hold_expires_at->isAfter($at ?? now());
    }

    /** Whether the guest may still cancel without a charge. */
    public function isWithinFreeCancellation(?DateTimeInterface $at = null): bool
    {
        return $this->cancellation_cutoff_at === null
            || $this->cancellation_cutoff_at->isAfter($at ?? now());
    }

    public function formattedTotal(): string
    {
        return Money::format($this->total_minor, $this->currency);
    }

    public function formattedNightlyRate(): string
    {
        return Money::format($this->nightly_rate_minor, $this->currency);
    }

    public function stayLabel(): string
    {
        return $this->check_in_date->format('j M Y').' — '.$this->check_out_date->format('j M Y')
            .' ('.$this->nights.' '.str('night')->plural($this->nights).')';
    }

    // ---- Payable ---------------------------------------------------------

    public function paymentDescription(): string
    {
        return $this->property_name_snapshot.' — '.$this->room_type_name_snapshot
            .', '.$this->rooms.' '.str('room')->plural($this->rooms)
            .' for '.$this->nights.' '.str('night')->plural($this->nights);
    }

    public function payableAmountMinor(): int
    {
        return (int) $this->total_minor;
    }

    /**
     * A stay accepts payment while it is pending review or confirmed. A
     * cancelled, declined, expired, or finished stay never does, whatever its
     * balance says.
     */
    public function acceptsPayment(): bool
    {
        return in_array($this->status, [
            PropertyBookingStatus::Pending,
            PropertyBookingStatus::Confirmed,
        ], true);
    }

    /**
     * Runs inside the settlement transaction and must be safe to call twice:
     * the loyalty marker is a firstOrCreate against a unique index, so a
     * duplicate webhook cannot award points a second time.
     */
    public function applySettledPayment(Payment $payment): void
    {
        $this->forceFill(['paid_minor' => $this->settledAmountMinor()])->save();

        if (! $this->isPaidInFull()) {
            return;
        }

        PropertyBookingEvent::query()->firstOrCreate(
            [
                'property_booking_id' => $this->getKey(),
                'event_type' => PropertyBookingEventType::LoyaltyEligible->value,
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
        return route('portal.property-bookings.show', ['customerPropertyBooking' => $this->reference]);
    }
}
