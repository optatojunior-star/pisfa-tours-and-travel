<?php

namespace App\Models;

use App\Enums\AirportTransferType;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AirportTransferRate extends Model
{
    use HasFactory;

    protected $fillable = [
        'airport_id',
        'airport_transfer_location_id',
        'transfer_type',
        'vehicle_type',
        'currency',
        'passenger_capacity',
        'luggage_capacity',
        'amount_minor',
        'estimated_duration_minutes',
        'effective_from',
        'effective_until',
        'is_active',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'transfer_type' => AirportTransferType::class,
            'passenger_capacity' => 'integer',
            'luggage_capacity' => 'integer',
            'amount_minor' => 'integer',
            'estimated_duration_minutes' => 'integer',
            'effective_from' => 'immutable_datetime',
            'effective_until' => 'immutable_datetime',
            'is_active' => 'boolean',
        ];
    }

    protected function vehicleType(): Attribute
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

    public function airport(): BelongsTo
    {
        return $this->belongsTo(Airport::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(AirportTransferLocation::class, 'airport_transfer_location_id');
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(AirportTransferBooking::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
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

    public function scopeForTransferType(Builder $query, AirportTransferType $type): Builder
    {
        return $query->where('transfer_type', $type->value);
    }

    public function scopeForVehicleType(Builder $query, string $vehicleType): Builder
    {
        return $query->where('vehicle_type', strtolower(trim($vehicleType)));
    }

    public function scopeForCurrency(Builder $query, string $currency): Builder
    {
        return $query->where('currency', strtoupper(trim($currency)));
    }

    public function scopeEffectiveAt(Builder $query, DateTimeInterface $at): Builder
    {
        return $query
            ->where('effective_from', '<=', $at)
            ->where(function (Builder $validity) use ($at): void {
                $validity
                    ->whereNull('effective_until')
                    ->orWhere('effective_until', '>', $at);
            });
    }

    public function supportsParty(int $passengers, int $luggage): bool
    {
        return $passengers >= 1
            && $luggage >= 0
            && $passengers <= $this->passenger_capacity
            && $luggage <= $this->luggage_capacity;
    }

    public function isEffectiveAt(DateTimeInterface $at): bool
    {
        return $this->is_active
            && ! $this->effective_from->isAfter($at)
            && ($this->effective_until === null || $this->effective_until->isAfter($at));
    }
}
