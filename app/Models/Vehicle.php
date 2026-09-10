<?php

namespace App\Models;

use App\Contracts\HasPhotographs;
use App\Enums\DocumentCategory;
use App\Enums\MaintenanceStatus;
use App\Enums\VehicleCatalogueStatus;
use App\Enums\VehicleOperationalStatus;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * @property VehicleCatalogueStatus $catalogue_status
 * @property VehicleOperationalStatus $operational_status
 * @property int $current_odometer_km
 * @property string $registration_plate
 */
class Vehicle extends Model implements HasPhotographs
{
    use HasFactory;

    protected $fillable = [
        'slug',
        'registration_plate',
        'make',
        'model',
        'year',
        'engine_cc',
        'color',
        'condition',
        'vehicle_type',
        'fuel_type',
        'transmission',
        'drive_type',
        'seating_capacity',
        'luggage_capacity',
        'current_odometer_km',
        'odometer_updated_at',
        'summary',
        'description',
        'catalogue_status',
        'operational_status',
        'published_at',
        'is_featured',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected $hidden = [
        'registration_plate',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'engine_cc' => 'integer',
            'seating_capacity' => 'integer',
            'luggage_capacity' => 'integer',
            'current_odometer_km' => 'integer',
            'odometer_updated_at' => 'immutable_datetime',
            'catalogue_status' => VehicleCatalogueStatus::class,
            'operational_status' => VehicleOperationalStatus::class,
            'published_at' => 'immutable_datetime',
            'is_featured' => 'boolean',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** @return MorphMany<Document, $this> */
    public function photographs(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable')
            ->where('category', DocumentCategory::VehicleMedia->value)
            ->orderBy('id');
    }

    /** @return HasMany<VehicleMedia, $this> */
    public function media(): HasMany
    {
        return $this->hasMany(VehicleMedia::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function coverMedia(): HasOne
    {
        return $this->hasOne(VehicleMedia::class)
            ->where('is_cover', true)
            ->oldestOfMany('sort_order');
    }

    public function hireRates(): HasMany
    {
        return $this->hasMany(VehicleHireRate::class)
            ->orderByDesc('effective_from')
            ->orderByDesc('id');
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(CarHireBooking::class);
    }

    /**
     * Showroom listings raised against this vehicle.
     *
     * More than one may exist over its life — a listing that was withdrawn and
     * a later one that sold — so this is a collection, not a single record.
     */
    public function listings(): HasMany
    {
        return $this->hasMany(VehicleListing::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public function scopePublished(Builder $query, ?DateTimeInterface $at = null): Builder
    {
        return $query
            ->where('catalogue_status', VehicleCatalogueStatus::Published->value)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', $at ?? now());
    }

    public function scopeOperational(Builder $query): Builder
    {
        return $query->where('operational_status', VehicleOperationalStatus::Available->value);
    }

    public function scopeAcceptingHire(Builder $query, ?DateTimeInterface $at = null): Builder
    {
        return $query->published($at)->operational();
    }

    public function scopeFeatured(Builder $query): Builder
    {
        return $query->where('is_featured', true);
    }

    public function scopeSearch(Builder $query, string $search): Builder
    {
        $search = trim($search);

        if ($search === '') {
            return $query;
        }

        return $query->where(function (Builder $nested) use ($search): void {
            $nested
                ->where('make', 'like', '%'.$search.'%')
                ->orWhere('model', 'like', '%'.$search.'%')
                ->orWhere('summary', 'like', '%'.$search.'%')
                ->orWhere('vehicle_type', 'like', '%'.$search.'%');
        });
    }

    public function scopeAvailableForInterval(
        Builder $query,
        DateTimeInterface $startsAt,
        DateTimeInterface $endsAt,
        ?DateTimeInterface $at = null,
    ): Builder {
        $at ??= now();

        return $query
            ->acceptingHire($at)
            ->whereDoesntHave('bookings', fn (Builder $bookings): Builder => $bookings
                ->holdingVehicle($at)
                ->overlapping($startsAt, $endsAt));
    }

    public function isPublishedAt(?DateTimeInterface $at = null): bool
    {
        $at ??= now();

        return $this->catalogue_status === VehicleCatalogueStatus::Published
            && $this->published_at !== null
            && ! $this->published_at->isAfter($at);
    }

    public function acceptsHireAt(?DateTimeInterface $at = null): bool
    {
        return $this->isPublishedAt($at)
            && $this->operational_status->acceptsHire();
    }

    // ---- Fleet management (F21) ------------------------------------------

    /** @return HasMany<VehicleMaintenanceRecord, $this> */
    public function maintenanceRecords(): HasMany
    {
        return $this->hasMany(VehicleMaintenanceRecord::class)
            ->orderByDesc('scheduled_for')
            ->orderByDesc('id');
    }

    /** @return HasMany<VehicleFuelLog, $this> */
    public function fuelLogs(): HasMany
    {
        return $this->hasMany(VehicleFuelLog::class)
            ->orderByDesc('filled_at')
            ->orderByDesc('id');
    }

    /** Compliance paperwork, filed through the F27 document system. */
    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable')
            ->whereIn('category', [
                DocumentCategory::InsuranceDocument->value,
                DocumentCategory::VehicleRegistration->value,
            ]);
    }

    /** Work still holding the vehicle, so it cannot quietly go back on hire. */
    public function openMaintenance(): HasMany
    {
        return $this->hasMany(VehicleMaintenanceRecord::class)
            ->whereIn('status', MaintenanceStatus::openValues());
    }

    public function formattedOdometer(): string
    {
        return number_format($this->current_odometer_km).' km';
    }
}
