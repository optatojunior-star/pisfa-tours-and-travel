<?php

namespace App\Models;

use App\Enums\InspectionPhase;
use App\Support\Fleet\InspectionChecklist;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One completed vehicle check.
 *
 * @property InspectionPhase $phase
 * @property array<string, string> $answers
 * @property bool $has_defects
 * @property bool $passed
 * @property int $odometer_km
 * @property Vehicle|null $vehicle
 */
class VehicleInspection extends Model
{
    use HasFactory;

    protected $fillable = [
        'vehicle_id',
        'driver_user_id',
        'driver_trip_id',
        'phase',
        'odometer_km',
        'answers',
        'has_defects',
        'passed',
        'defect_notes',
        'maintenance_record_id',
    ];

    protected function casts(): array
    {
        return [
            'phase' => InspectionPhase::class,
            'answers' => 'array',
            'odometer_km' => 'integer',
            'has_defects' => 'boolean',
            'passed' => 'boolean',
        ];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_user_id');
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(DriverTrip::class, 'driver_trip_id');
    }

    public function maintenanceRecord(): BelongsTo
    {
        return $this->belongsTo(VehicleMaintenanceRecord::class, 'maintenance_record_id');
    }

    public function scopeWithDefects(Builder $query): Builder
    {
        return $query->where('has_defects', true);
    }

    /** @return list<string> */
    public function defectKeys(): array
    {
        return InspectionChecklist::defects((array) $this->answers);
    }

    /** @return list<string> */
    public function defectLabels(): array
    {
        return array_map(
            static fn (string $key): string => InspectionChecklist::label($key),
            $this->defectKeys(),
        );
    }
}
