<?php

namespace App\Models;

use App\Enums\DocumentCategory;
use App\Enums\ListingStatus;
use App\Support\Money;
use App\Support\VehicleSpecification;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A vehicle offered for sale in the showroom.
 *
 * Specification is snapshotted rather than read through the fleet vehicle, so a
 * listing stays truthful about what was advertised even after the vehicle is
 * sold, retired, or edited — and so stock that was never in the fleet is a
 * complete record on its own.
 *
 * @property ListingStatus $status
 * @property string $reference
 * @property string $slug
 * @property string $title
 * @property string $currency
 * @property int $asking_price_minor
 * @property int|null $sold_price_minor
 * @property int|null $vehicle_id
 * @property bool $is_featured
 * @property CarbonImmutable|null $listed_at
 * @property CarbonImmutable|null $sold_at
 * @property Vehicle|null $vehicle
 */
class VehicleListing extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'reference',
        'slug',
        'vehicle_id',
        'status',
        'title',
        'make',
        'model',
        'year',
        'engine_cc',
        'body_type',
        'fuel_type',
        'transmission',
        'drive_type',
        'colour',
        'mileage_km',
        'seating_capacity',
        'condition',
        'description',
        'internal_notes',
        'asking_price_minor',
        'currency',
        'sold_price_minor',
        'is_negotiable',
        'is_featured',
        'listed_at',
        'reserved_at',
        'sold_at',
        'withdrawn_at',
        'closure_reason',
        'sold_to_enquiry_id',
        'created_by_user_id',
    ];

    protected $hidden = ['internal_notes'];

    protected function casts(): array
    {
        return [
            'status' => ListingStatus::class,
            'year' => 'integer',
            'engine_cc' => 'integer',
            'mileage_km' => 'integer',
            'seating_capacity' => 'integer',
            'asking_price_minor' => 'integer',
            'sold_price_minor' => 'integer',
            'is_negotiable' => 'boolean',
            'is_featured' => 'boolean',
            'listed_at' => 'immutable_datetime',
            'reserved_at' => 'immutable_datetime',
            'sold_at' => 'immutable_datetime',
            'withdrawn_at' => 'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return HasMany<VehicleSalesEnquiry, $this> */
    public function enquiries(): HasMany
    {
        return $this->hasMany(VehicleSalesEnquiry::class)->latest('id');
    }

    public function soldToEnquiry(): BelongsTo
    {
        return $this->belongsTo(VehicleSalesEnquiry::class, 'sold_to_enquiry_id');
    }

    /** @return MorphMany<Document, $this> */
    public function media(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable')
            ->where('category', DocumentCategory::VehicleMedia->value);
    }

    /**
     * The only scope a public surface may use.
     *
     * A sold car stays visible on purpose — a showroom with recent sales reads
     * as a going concern — but it accepts no enquiries.
     */
    public function scopePublic(Builder $query): Builder
    {
        return $query->whereIn('status', ListingStatus::publicValues());
    }

    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('status', ListingStatus::Available->value);
    }

    public function scopeFeatured(Builder $query): Builder
    {
        return $query->where('is_featured', true);
    }

    /** Listings still counted as stock on hand. */
    public function scopeInStock(Builder $query): Builder
    {
        return $query->whereIn('status', [
            ListingStatus::Available->value,
            ListingStatus::Reserved->value,
        ]);
    }

    public function scopeSearch(Builder $query, string $search): Builder
    {
        $search = trim($search);

        if ($search === '') {
            return $query;
        }

        return $query->where(function (Builder $nested) use ($search): void {
            $nested->where('title', 'like', '%'.$search.'%')
                ->orWhere('make', 'like', '%'.$search.'%')
                ->orWhere('model', 'like', '%'.$search.'%')
                ->orWhere('reference', 'like', '%'.$search.'%');
        });
    }

    public function canTransitionTo(ListingStatus $next): bool
    {
        return $this->status->canTransitionTo($next);
    }

    public function acceptsEnquiries(): bool
    {
        return $this->status->acceptsEnquiries();
    }

    public function formattedAskingPrice(): string
    {
        return Money::format($this->asking_price_minor, $this->currency);
    }

    public function formattedSoldPrice(): ?string
    {
        return $this->sold_price_minor === null
            ? null
            : Money::format($this->sold_price_minor, $this->currency);
    }

    public function formattedMileage(): string
    {
        return $this->mileage_km === null ? 'Not stated' : number_format($this->mileage_km).' km';
    }

    /** How long it has been on the market, for the console. */
    public function daysListed(?DateTimeInterface $at = null): ?int
    {
        if ($this->listed_at === null) {
            return null;
        }

        $end = $this->sold_at ?? $this->withdrawn_at ?? ($at ?? now());

        return (int) $this->listed_at->diffInDays($end);
    }

    public function coverUrl(): ?string
    {
        $cover = $this->relationLoaded('media')
            ? $this->media->first()
            : $this->media()->first();

        return $cover?->url();
    }

    /**
     * The one-line specification under a showroom card.
     *
     * Reads through VehicleSpecification so a stored "safari_van" is shown as
     * "Safari van (pop-up roof)" rather than the storage key, and so engine and
     * drive — the two facts a buyer here asks about first — finally appear.
     */
    public function specSummary(): string
    {
        return trim(implode(' · ', array_filter([
            $this->year,
            VehicleSpecification::formatEngine($this->engine_cc),
            $this->transmission === null ? null : VehicleSpecification::label(VehicleSpecification::transmissions(), $this->transmission),
            $this->fuel_type === null ? null : VehicleSpecification::label(VehicleSpecification::fuelTypes(), $this->fuel_type),
            $this->drive_type === null ? null : VehicleSpecification::label(VehicleSpecification::driveTypes(), $this->drive_type),
            $this->formattedMileage(),
        ])));
    }
}
