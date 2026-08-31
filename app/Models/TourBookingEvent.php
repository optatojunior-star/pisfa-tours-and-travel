<?php

namespace App\Models;

use App\Enums\TourBookingEventType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property TourBookingEventType $event_type
 * @property CarbonImmutable|null $processed_at
 * @property TourBooking|null $booking
 */
class TourBookingEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'tour_booking_id',
        'event_type',
        'payload',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'event_type' => TourBookingEventType::class,
            'payload' => 'array',
            'processed_at' => 'immutable_datetime',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(TourBooking::class, 'tour_booking_id');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('processed_at');
    }

    public function isProcessed(): bool
    {
        return $this->processed_at !== null;
    }
}
