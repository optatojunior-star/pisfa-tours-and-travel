<?php

namespace App\Models;

use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a room type costs per night, for a season and a currency.
 *
 * Separate rows rather than a base rate with modifiers, because a season is a
 * different price rather than a percentage of one, and because the row a
 * booking was priced from is kept on the booking — so re-pricing next year's
 * high season cannot change what last year's guest was charged.
 *
 * @property int $nightly_rate_minor
 * @property string $currency
 * @property int $minimum_nights
 * @property bool $is_active
 * @property CarbonImmutable $effective_from
 * @property CarbonImmutable|null $effective_until
 * @property int|null $property_room_type_id
 * @property PropertyRoomType|null $roomType
 */
class PropertyRoomRate extends Model
{
    use HasFactory;

    protected $fillable = [
        'property_room_type_id',
        'currency',
        'nightly_rate_minor',
        'effective_from',
        'effective_until',
        'minimum_nights',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'nightly_rate_minor' => 'integer',
            'minimum_nights' => 'integer',
            'is_active' => 'boolean',
            'effective_from' => 'immutable_date',
            'effective_until' => 'immutable_date',
        ];
    }

    public function roomType(): BelongsTo
    {
        return $this->belongsTo(PropertyRoomType::class, 'property_room_type_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function formattedNightlyRate(): string
    {
        return Money::format($this->nightly_rate_minor, $this->currency);
    }

    public function seasonLabel(): string
    {
        $from = $this->effective_from->format('j M Y');

        return $this->effective_until === null
            ? 'From '.$from
            : $from.' — '.$this->effective_until->format('j M Y');
    }
}
