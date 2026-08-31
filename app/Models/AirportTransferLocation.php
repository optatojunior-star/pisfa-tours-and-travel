<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class AirportTransferLocation extends Model
{
    use HasFactory;

    protected $fillable = [
        'slug',
        'name',
        'region',
        'description',
        'is_active',
        'sort_order',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected function slug(): Attribute
    {
        return Attribute::make(
            set: static fn (mixed $value): string => Str::slug((string) $value),
        );
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function rates(): HasMany
    {
        return $this->hasMany(AirportTransferRate::class)
            ->orderByDesc('effective_from')
            ->orderByDesc('id');
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(AirportTransferBooking::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name')->orderBy('id');
    }

    public function scopeSearch(Builder $query, string $search): Builder
    {
        $search = trim($search);

        if ($search === '') {
            return $query;
        }

        return $query->where(function (Builder $matches) use ($search): void {
            $matches
                ->where('name', 'like', '%'.$search.'%')
                ->orWhere('region', 'like', '%'.$search.'%')
                ->orWhere('description', 'like', '%'.$search.'%');
        });
    }

    public function isActive(): bool
    {
        return $this->is_active;
    }
}
