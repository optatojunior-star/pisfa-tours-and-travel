<?php

namespace App\Models;

use App\Enums\AirportTransferEventType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AirportTransferEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'airport_transfer_booking_id',
        'event_type',
        'payload',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'event_type' => AirportTransferEventType::class,
            'payload' => 'array',
            'processed_at' => 'immutable_datetime',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(AirportTransferBooking::class, 'airport_transfer_booking_id');
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
