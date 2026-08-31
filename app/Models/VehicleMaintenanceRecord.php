<?php

namespace App\Models;

use App\Enums\MaintenanceStatus;
use App\Enums\MaintenanceType;
use App\Support\Money;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One piece of work on one vehicle: a service, a repair, an inspection.
 *
 * @property MaintenanceStatus $status
 * @property MaintenanceType $type
 * @property string $reference
 * @property string $title
 * @property string $currency
 * @property int $cost_minor
 * @property int|null $odometer_km
 * @property int|null $next_due_odometer_km
 * @property CarbonImmutable|null $scheduled_for
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $next_due_on
 * @property CarbonImmutable|null $due_alert_sent_at
 * @property Vehicle|null $vehicle
 */
class VehicleMaintenanceRecord extends Model
{
    use HasFactory;

    protected $fillable = [
        'reference',
        'vehicle_id',
        'type',
        'status',
        'title',
        'description',
        'vendor',
        'scheduled_for',
        'started_at',
        'completed_at',
        'cancelled_at',
        'closure_reason',
        'odometer_km',
        'cost_minor',
        'currency',
        'next_due_on',
        'next_due_odometer_km',
        'recorded_by_user_id',
        'internal_notes',
        'due_alert_sent_at',
    ];

    protected $hidden = ['internal_notes'];

    protected function casts(): array
    {
        return [
            'type' => MaintenanceType::class,
            'status' => MaintenanceStatus::class,
            'scheduled_for' => 'immutable_date',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'odometer_km' => 'integer',
            'cost_minor' => 'integer',
            'next_due_on' => 'immutable_date',
            'next_due_odometer_km' => 'integer',
            'due_alert_sent_at' => 'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'reference';
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', MaintenanceStatus::openValues());
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', MaintenanceStatus::Completed->value);
    }

    /**
     * Completed recurring work whose next service point has arrived.
     *
     * Either the date or the odometer can trigger it: whichever comes first is
     * what a workshop actually goes by.
     */
    public function scopeDue(Builder $query, ?DateTimeInterface $at = null): Builder
    {
        $at ??= now();

        return $query
            ->where('status', MaintenanceStatus::Completed->value)
            ->where(fn (Builder $nested) => $nested
                ->whereDate('next_due_on', '<=', $at->format('Y-m-d'))
                ->orWhereRaw(
                    'next_due_odometer_km is not null and next_due_odometer_km <= ('
                    .'select current_odometer_km from vehicles where vehicles.id = vehicle_maintenance_records.vehicle_id)',
                ));
    }

    public function canTransitionTo(MaintenanceStatus $next): bool
    {
        return $this->status->canTransitionTo($next);
    }

    public function formattedCost(): string
    {
        return Money::format($this->cost_minor, $this->currency);
    }

    /** Whether this record's next service point has been reached. */
    public function isDue(?DateTimeInterface $at = null): bool
    {
        if ($this->status !== MaintenanceStatus::Completed) {
            return false;
        }

        $at ??= now();

        if ($this->next_due_on !== null && ! $this->next_due_on->endOfDay()->isAfter($at)) {
            return true;
        }

        return $this->next_due_odometer_km !== null
            && $this->vehicle !== null
            && $this->vehicle->current_odometer_km >= $this->next_due_odometer_km;
    }

    /** Why it is due, so an alert can say something useful. */
    public function dueReason(?DateTimeInterface $at = null): ?string
    {
        if (! $this->isDue($at)) {
            return null;
        }

        $at ??= now();
        $reasons = [];

        if ($this->next_due_on !== null && ! $this->next_due_on->endOfDay()->isAfter($at)) {
            $reasons[] = 'due on '.$this->next_due_on->format('j M Y');
        }

        if ($this->next_due_odometer_km !== null
            && $this->vehicle !== null
            && $this->vehicle->current_odometer_km >= $this->next_due_odometer_km) {
            $reasons[] = 'past '.number_format($this->next_due_odometer_km).' km';
        }

        return implode(' and ', $reasons);
    }
}
