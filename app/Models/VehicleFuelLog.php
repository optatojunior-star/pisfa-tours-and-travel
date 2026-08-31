<?php

namespace App\Models;

use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One refuelling.
 *
 * Volume is stored in whole millilitres for the same reason money is stored in
 * minor units: no float ever participates in a stored quantity. Litres are a
 * display concern.
 *
 * @property int $odometer_km
 * @property int $volume_ml
 * @property int $cost_minor
 * @property string $currency
 * @property bool $is_full_tank
 * @property CarbonImmutable $filled_at
 * @property Vehicle|null $vehicle
 */
class VehicleFuelLog extends Model
{
    use HasFactory;

    public const ML_PER_LITRE = 1000;

    protected $fillable = [
        'vehicle_id',
        'filled_at',
        'odometer_km',
        'volume_ml',
        'cost_minor',
        'currency',
        'station',
        'is_full_tank',
        'recorded_by_user_id',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'filled_at' => 'immutable_datetime',
            'odometer_km' => 'integer',
            'volume_ml' => 'integer',
            'cost_minor' => 'integer',
            'is_full_tank' => 'boolean',
        ];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    public function scopeForVehicle(Builder $query, Vehicle|int $vehicle): Builder
    {
        return $query->where('vehicle_id', $vehicle instanceof Vehicle ? $vehicle->getKey() : $vehicle);
    }

    /** Only a full tank closes a measurable interval. */
    public function scopeFullTank(Builder $query): Builder
    {
        return $query->where('is_full_tank', true);
    }

    public function litres(): float
    {
        return $this->volume_ml / self::ML_PER_LITRE;
    }

    public function formattedVolume(): string
    {
        return number_format($this->litres(), 2).' L';
    }

    public function formattedCost(): string
    {
        return Money::format($this->cost_minor, $this->currency);
    }

    /** Price per litre, derived for display only and never stored. */
    public function formattedPricePerLitre(): string
    {
        if ($this->volume_ml < 1) {
            return '—';
        }

        $perLitre = (int) round($this->cost_minor * self::ML_PER_LITRE / $this->volume_ml);

        return Money::format($perLitre, $this->currency).'/L';
    }
}
