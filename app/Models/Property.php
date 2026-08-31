<?php

namespace App\Models;

use App\Enums\DocumentCategory;
use App\Enums\PropertyStatus;
use App\Enums\PropertyType;
use App\Models\Concerns\HasReviews;
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
 * Somewhere PISFA can put guests: a hotel, lodge, guest house, or apartment.
 *
 * The property is the marketing and location record; the bookable inventory
 * lives on its room types, because a property is not a thing you can be given —
 * a room is.
 *
 * @property PropertyStatus $status
 * @property PropertyType $property_type
 * @property string $slug
 * @property string $name
 * @property string $region
 * @property string|null $district
 * @property string $summary
 * @property string|null $internal_notes
 * @property int $cancellation_cutoff_hours
 * @property bool $is_featured
 * @property CarbonImmutable|null $published_at
 */
class Property extends Model
{
    use HasFactory;
    use HasReviews;
    use SoftDeletes;

    protected $fillable = [
        'slug',
        'name',
        'property_type',
        'region',
        'district',
        'address',
        'summary',
        'description',
        'directions',
        'internal_notes',
        'check_in_from',
        'check_out_by',
        'cancellation_cutoff_hours',
        'status',
        'published_at',
        'is_featured',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected $hidden = ['internal_notes'];

    protected function casts(): array
    {
        return [
            'status' => PropertyStatus::class,
            'property_type' => PropertyType::class,
            'cancellation_cutoff_hours' => 'integer',
            'is_featured' => 'boolean',
            'published_at' => 'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** @return HasMany<PropertyRoomType, $this> */
    public function roomTypes(): HasMany
    {
        return $this->hasMany(PropertyRoomType::class)->orderBy('name');
    }

    /** @return HasMany<PropertyBooking, $this> */
    public function bookings(): HasMany
    {
        return $this->hasMany(PropertyBooking::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return MorphMany<Document, $this> */
    public function media(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable')
            ->where('category', DocumentCategory::PropertyMedia->value);
    }

    /**
     * The only scope a public surface may use.
     *
     * Status *and* date, so a scheduling mistake can only hide a property, never
     * leak a draft one.
     */
    public function scopePublished(Builder $query, ?DateTimeInterface $at = null): Builder
    {
        return $query
            ->where('status', PropertyStatus::Published->value)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', $at ?? now());
    }

    public function scopeFeatured(Builder $query): Builder
    {
        return $query->where('is_featured', true);
    }

    public function scopeInRegion(Builder $query, string $region): Builder
    {
        return $query->where('region', $region);
    }

    public function scopeSearch(Builder $query, string $search): Builder
    {
        $search = trim($search);

        if ($search === '') {
            return $query;
        }

        return $query->where(function (Builder $nested) use ($search): void {
            $nested->where('name', 'like', '%'.$search.'%')
                ->orWhere('region', 'like', '%'.$search.'%')
                ->orWhere('district', 'like', '%'.$search.'%')
                ->orWhere('summary', 'like', '%'.$search.'%');
        });
    }

    public function isPublishedAt(?DateTimeInterface $at = null): bool
    {
        return $this->status === PropertyStatus::Published
            && $this->published_at !== null
            && ! $this->published_at->isAfter($at ?? now());
    }

    public function canTransitionTo(PropertyStatus $next): bool
    {
        return $this->status->canTransitionTo($next);
    }

    public function locationLabel(): string
    {
        return trim(implode(', ', array_filter([$this->district, $this->region])));
    }

    public function coverUrl(): ?string
    {
        $cover = $this->relationLoaded('media')
            ? $this->media->first()
            : $this->media()->first();

        return $cover?->url();
    }
}
