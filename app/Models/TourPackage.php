<?php

namespace App\Models;

use App\Enums\TourPackageItemType;
use App\Enums\TourPackageStatus;
use App\Models\Concerns\HasReviews;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class TourPackage extends Model
{
    use HasFactory;
    use HasReviews;

    protected $fillable = [
        'tour_category_id',
        'name',
        'slug',
        'destination',
        'summary',
        'description',
        'status',
        'published_at',
        'is_featured',
        'duration_days',
        'base_price_minor',
        'currency',
        'min_travelers',
        'max_travelers',
        'cancellation_cutoff_hours',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => TourPackageStatus::class,
            'published_at' => 'immutable_datetime',
            'is_featured' => 'boolean',
            'duration_days' => 'integer',
            'base_price_minor' => 'integer',
            'min_travelers' => 'integer',
            'max_travelers' => 'integer',
            'cancellation_cutoff_hours' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(TourCategory::class, 'tour_category_id');
    }

    public function media(): HasMany
    {
        return $this->hasMany(TourPackageMedia::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function coverMedia(): HasOne
    {
        return $this->hasOne(TourPackageMedia::class)
            ->where('is_cover', true)
            ->oldestOfMany('sort_order');
    }

    public function itineraryDays(): HasMany
    {
        return $this->hasMany(TourItineraryDay::class)
            ->orderBy('sort_order')
            ->orderBy('day_number');
    }

    public function items(): HasMany
    {
        return $this->hasMany(TourPackageItem::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function inclusions(): HasMany
    {
        return $this->hasMany(TourPackageItem::class)
            ->where('item_type', TourPackageItemType::Inclusion->value)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function exclusions(): HasMany
    {
        return $this->hasMany(TourPackageItem::class)
            ->where('item_type', TourPackageItemType::Exclusion->value)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function departures(): HasMany
    {
        return $this->hasMany(TourDeparture::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(TourBooking::class);
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
        $at ??= now();

        return $query
            ->where('status', TourPackageStatus::Published->value)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', $at)
            ->whereHas('category', fn (Builder $category): Builder => $category->active());
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
                ->where('name', 'like', '%'.$search.'%')
                ->orWhere('destination', 'like', '%'.$search.'%')
                ->orWhere('summary', 'like', '%'.$search.'%');
        });
    }
}
