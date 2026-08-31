<?php

namespace App\Models;

use App\Enums\TourPackageItemType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TourPackageItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'tour_package_id',
        'item_type',
        'content',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'item_type' => TourPackageItemType::class,
            'sort_order' => 'integer',
        ];
    }

    public function tourPackage(): BelongsTo
    {
        return $this->belongsTo(TourPackage::class);
    }

    public function scopeInclusions(Builder $query): Builder
    {
        return $query->where('item_type', TourPackageItemType::Inclusion->value);
    }

    public function scopeExclusions(Builder $query): Builder
    {
        return $query->where('item_type', TourPackageItemType::Exclusion->value);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }
}
