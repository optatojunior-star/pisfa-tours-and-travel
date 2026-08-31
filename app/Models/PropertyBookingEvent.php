<?php

namespace App\Models;

use App\Enums\PropertyBookingEventType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A durable "this already happened" marker for one booking.
 *
 * The unique index on `(property_booking_id, event_type)` is what makes a
 * repeated sweep or a retried job harmless: the second insert fails at the
 * database rather than sending a second email.
 *
 * @property PropertyBookingEventType $event_type
 * @property array<string, mixed>|null $payload
 * @property CarbonImmutable|null $processed_at
 * @property PropertyBooking|null $booking
 */
class PropertyBookingEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'property_booking_id',
        'event_type',
        'payload',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'event_type' => PropertyBookingEventType::class,
            'payload' => 'array',
            'processed_at' => 'immutable_datetime',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(PropertyBooking::class, 'property_booking_id');
    }

    public function scopeUnprocessed(Builder $query): Builder
    {
        return $query->whereNull('processed_at');
    }

    public function isProcessed(): bool
    {
        return $this->processed_at !== null;
    }
}
