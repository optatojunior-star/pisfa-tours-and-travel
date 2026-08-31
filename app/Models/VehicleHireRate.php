<?php

namespace App\Models;

use App\Enums\HireMode;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VehicleHireRate extends Model
{
    use HasFactory;

    protected $fillable = [
        'vehicle_id',
        'currency',
        'self_drive_daily_minor',
        'with_driver_daily_minor',
        'security_deposit_minor',
        'effective_from',
        'effective_until',
        'is_active',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'self_drive_daily_minor' => 'integer',
            'with_driver_daily_minor' => 'integer',
            'security_deposit_minor' => 'integer',
            'effective_from' => 'immutable_datetime',
            'effective_until' => 'immutable_datetime',
            'is_active' => 'boolean',
        ];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(CarHireBooking::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeForCurrency(Builder $query, string $currency): Builder
    {
        return $query->where('currency', strtoupper(trim($currency)));
    }

    public function scopeForMode(Builder $query, HireMode $mode): Builder
    {
        return $query->whereNotNull($mode === HireMode::SelfDrive
            ? 'self_drive_daily_minor'
            : 'with_driver_daily_minor');
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

    public function scopeCoveringInterval(
        Builder $query,
        DateTimeInterface $startsAt,
        DateTimeInterface $endsAt,
    ): Builder {
        return $query
            ->where('effective_from', '<=', $startsAt)
            ->where(function (Builder $validity) use ($endsAt): void {
                $validity
                    ->whereNull('effective_until')
                    ->orWhere('effective_until', '>=', $endsAt);
            });
    }

    public function supportsMode(HireMode $mode): bool
    {
        return $this->rateFor($mode) !== null;
    }

    public function rateFor(HireMode $mode): ?int
    {
        return match ($mode) {
            HireMode::SelfDrive => $this->self_drive_daily_minor,
            HireMode::WithDriver => $this->with_driver_daily_minor,
        };
    }

    public function isEffectiveAt(DateTimeInterface $at): bool
    {
        return $this->is_active
            && ! $this->effective_from->isAfter($at)
            && ($this->effective_until === null || $this->effective_until->isAfter($at));
    }
}
