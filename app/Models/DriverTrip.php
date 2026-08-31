<?php

namespace App\Models;

use App\Enums\DriverTripStatus;
use App\Enums\InspectionPhase;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A driver's record of one job: wheels turning, odometer out and in.
 *
 * @property DriverTripStatus $status
 * @property string $reference
 * @property int|null $vehicle_id
 * @property int|null $start_odometer_km
 * @property int|null $end_odometer_km
 * @property int|null $distance_km
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $completed_at
 * @property Vehicle|null $vehicle
 * @property User|null $driver
 */
class DriverTrip extends Model
{
    use HasFactory;

    protected $fillable = [
        'reference',
        'assignment_type',
        'assignment_id',
        'driver_user_id',
        'vehicle_id',
        'status',
        'started_at',
        'completed_at',
        'closure_reason',
        'start_odometer_km',
        'end_odometer_km',
        'distance_km',
        'driver_notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => DriverTripStatus::class,
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'start_odometer_km' => 'integer',
            'end_odometer_km' => 'integer',
            'distance_km' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'reference';
    }

    public function assignment(): MorphTo
    {
        return $this->morphTo('assignment');
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_user_id');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /** @return HasMany<VehicleInspection, $this> */
    public function inspections(): HasMany
    {
        return $this->hasMany(VehicleInspection::class)->orderBy('id');
    }

    public function scopeForDriver(Builder $query, User|int $driver): Builder
    {
        return $query->where('driver_user_id', $driver instanceof User ? $driver->getKey() : $driver);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', DriverTripStatus::openValues());
    }

    public function canTransitionTo(DriverTripStatus $next): bool
    {
        return $this->status->canTransitionTo($next);
    }

    /** A trip in a vehicle outside the fleet has no odometer to capture. */
    public function tracksOdometer(): bool
    {
        return $this->vehicle_id !== null;
    }

    public function inspectionFor(InspectionPhase $phase): ?VehicleInspection
    {
        return $this->inspections->firstWhere('phase', $phase);
    }

    public function formattedDistance(): string
    {
        return $this->distance_km === null ? '—' : number_format($this->distance_km).' km';
    }
}
