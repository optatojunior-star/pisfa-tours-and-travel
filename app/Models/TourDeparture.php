<?php

namespace App\Models;

use App\Enums\TourBookingStatus;
use App\Enums\TourDepartureStatus;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property TourDepartureStatus $status
 * @property int $capacity
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 * @property CarbonImmutable $cancellation_cutoff_at
 */
class TourDeparture extends Model
{
    use HasFactory;

    protected $fillable = [
        'tour_package_id',
        'starts_at',
        'ends_at',
        'cancellation_cutoff_at',
        'capacity',
        'price_override_minor',
        'currency',
        'status',
        'meeting_point',
        'customer_notes',
        'internal_notes',
    ];

    protected $hidden = [
        'internal_notes',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'cancellation_cutoff_at' => 'immutable_datetime',
            'capacity' => 'integer',
            'price_override_minor' => 'integer',
            'status' => TourDepartureStatus::class,
        ];
    }

    public function tourPackage(): BelongsTo
    {
        return $this->belongsTo(TourPackage::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(TourBooking::class);
    }

    public function capacityHoldingBookings(): HasMany
    {
        return $this->hasMany(TourBooking::class)
            ->whereIn('status', TourBookingStatus::capacityHoldingValues());
    }

    public function scopeUpcoming(Builder $query, ?DateTimeInterface $at = null): Builder
    {
        return $query->where('starts_at', '>', $at ?? now());
    }

    public function scopeAcceptingBookings(Builder $query, ?DateTimeInterface $at = null): Builder
    {
        return $query
            ->where('status', TourDepartureStatus::Scheduled->value)
            ->upcoming($at);
    }

    public function effectivePriceMinor(): int
    {
        return $this->price_override_minor ?? $this->tourPackage->base_price_minor;
    }

    public function effectiveCurrency(): string
    {
        return $this->price_override_minor !== null
            ? ($this->currency ?? $this->tourPackage->currency)
            : $this->tourPackage->currency;
    }
}
