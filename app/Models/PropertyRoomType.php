<?php

namespace App\Models;

use App\Support\Money;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A class of room within a property, and the thing that is actually contended.
 *
 * `quantity` is what makes accommodation different from car hire. A hire
 * vehicle is one physical object, so any overlap is a conflict. A room type is
 * N interchangeable rooms, so the question is not "does this overlap?" but
 * "how many are already committed on the busiest night of this stay?".
 *
 * @property int $quantity
 * @property int $max_adults
 * @property int $max_children
 * @property bool $is_active
 * @property string $name
 * @property string $slug
 * @property int|null $property_id
 * @property Property|null $property
 */
class PropertyRoomType extends Model
{
    use HasFactory;

    protected $fillable = [
        'property_id',
        'slug',
        'name',
        'description',
        'quantity',
        'max_adults',
        'max_children',
        'bed_configuration',
        'size_sqm',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'max_adults' => 'integer',
            'max_children' => 'integer',
            'size_sqm' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /** @return HasMany<PropertyRoomRate, $this> */
    public function rates(): HasMany
    {
        return $this->hasMany(PropertyRoomRate::class)->orderByDesc('effective_from')->orderByDesc('id');
    }

    /** @return HasMany<PropertyBooking, $this> */
    public function bookings(): HasMany
    {
        return $this->hasMany(PropertyBooking::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function sleeps(): int
    {
        return $this->max_adults + $this->max_children;
    }

    public function occupancyLabel(): string
    {
        $parts = [$this->max_adults.' '.str('adult')->plural($this->max_adults)];

        if ($this->max_children > 0) {
            $parts[] = $this->max_children.' '.str('child')->plural($this->max_children);
        }

        return implode(' + ', $parts);
    }

    /**
     * Rooms of this type still free on the busiest night of the interval.
     *
     * Deliberately the *minimum* across nights rather than a total: a guest
     * needs the same room for every night of their stay, so a type with one
     * room free on Monday and three on Tuesday can take exactly one booking,
     * not four.
     *
     * `$excludingBookingId` lets a booking be re-checked without counting
     * against itself, which is what a date change needs.
     */
    public function availableRooms(
        DateTimeInterface|string $checkIn,
        DateTimeInterface|string $checkOut,
        ?int $excludingBookingId = null,
        ?DateTimeInterface $at = null,
    ): int {
        $checkIn = CarbonImmutable::parse($checkIn)->startOfDay();
        $checkOut = CarbonImmutable::parse($checkOut)->startOfDay();

        if (! $checkOut->isAfter($checkIn)) {
            return 0;
        }

        $committed = $this->committedRoomsByNight($checkIn, $checkOut, $excludingBookingId, $at);

        $busiest = $committed === [] ? 0 : max($committed);

        return max(0, $this->quantity - $busiest);
    }

    public function hasAvailability(
        DateTimeInterface|string $checkIn,
        DateTimeInterface|string $checkOut,
        int $rooms = 1,
        ?int $excludingBookingId = null,
        ?DateTimeInterface $at = null,
    ): bool {
        return $this->availableRooms($checkIn, $checkOut, $excludingBookingId, $at) >= $rooms;
    }

    /**
     * Rooms committed on each night of the interval, keyed by date.
     *
     * Walking the nights in PHP rather than asking the database for a per-night
     * roll-up: a stay is at most a few weeks, the booking rows are already
     * narrowed by the index, and the alternative is a recursive date series that
     * MariaDB and SQLite do not write the same way.
     *
     * @return array<string, int>
     */
    public function committedRoomsByNight(
        DateTimeInterface|string $checkIn,
        DateTimeInterface|string $checkOut,
        ?int $excludingBookingId = null,
        ?DateTimeInterface $at = null,
    ): array {
        $checkIn = CarbonImmutable::parse($checkIn)->startOfDay();
        $checkOut = CarbonImmutable::parse($checkOut)->startOfDay();
        $at ??= now();

        $overlapping = PropertyBooking::query()
            ->where('property_room_type_id', $this->getKey())
            ->holdingInventory($at)
            // Half-open nights: a stay ending on the 12th does not occupy the
            // night of the 12th, so same-day turnover is not a conflict.
            ->where('check_in_date', '<', $checkOut->toDateString())
            ->where('check_out_date', '>', $checkIn->toDateString())
            ->when(
                $excludingBookingId !== null,
                fn (Builder $query): Builder => $query->whereKeyNot($excludingBookingId),
            )
            ->get(['id', 'rooms', 'check_in_date', 'check_out_date']);

        $nights = [];

        for ($night = $checkIn; $night->isBefore($checkOut); $night = $night->addDay()) {
            $key = $night->toDateString();
            $nights[$key] = 0;

            foreach ($overlapping as $booking) {
                if ($booking->occupiesNight($night)) {
                    $nights[$key] += (int) $booking->rooms;
                }
            }
        }

        return $nights;
    }

    /**
     * The rate covering an entire stay in one currency.
     *
     * The *whole* stay has to fall inside one window. High season is not a
     * discount on a base rate, it is a different price, so a stay that straddles
     * two seasons has no single nightly rate and must be quoted by hand rather
     * than silently billed at whichever window happened to sort first.
     */
    public function rateFor(
        DateTimeInterface|string $checkIn,
        DateTimeInterface|string $checkOut,
        string $currency,
    ): ?PropertyRoomRate {
        $checkIn = CarbonImmutable::parse($checkIn)->startOfDay();
        // The last night slept is the night before checkout.
        $lastNight = CarbonImmutable::parse($checkOut)->startOfDay()->subDay();

        // whereDate throughout: the date cast writes a midnight time component,
        // so a plain string comparison would read a season starting exactly on
        // the arrival date as starting *after* it, and tell the guest those
        // dates are unpriced on the first day of every season.
        return PropertyRoomRate::query()
            ->where('property_room_type_id', $this->getKey())
            ->where('currency', strtoupper($currency))
            ->where('is_active', true)
            ->whereDate('effective_from', '<=', $checkIn->toDateString())
            ->where(function (Builder $query) use ($lastNight): void {
                $query->whereNull('effective_until')
                    ->orWhereDate('effective_until', '>=', $lastNight->toDateString());
            })
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();
    }

    /** The lowest active nightly rate, for the catalogue card. */
    public function fromPrice(string $currency): ?string
    {
        $rate = $this->relationLoaded('rates')
            ? $this->rates->where('currency', strtoupper($currency))->where('is_active', true)->sortBy('nightly_rate_minor')->first()
            : $this->rates()->where('currency', strtoupper($currency))->where('is_active', true)->orderBy('nightly_rate_minor')->first();

        return $rate === null ? null : Money::format($rate->nightly_rate_minor, $rate->currency);
    }
}
