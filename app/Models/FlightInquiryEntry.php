<?php

namespace App\Models;

use App\Enums\FlightInquiryEntryType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FlightInquiryEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'flight_inquiry_id',
        'author_user_id',
        'entry_type',
        'body',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'entry_type' => FlightInquiryEntryType::class,
            'payload' => 'array',
        ];
    }

    public function inquiry(): BelongsTo
    {
        return $this->belongsTo(FlightInquiry::class, 'flight_inquiry_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    public function scopeCommunications(Builder $query): Builder
    {
        return $query->whereIn('entry_type', array_map(
            static fn (FlightInquiryEntryType $type): string => $type->value,
            array_filter(
                FlightInquiryEntryType::cases(),
                static fn (FlightInquiryEntryType $type): bool => $type->isCommunication(),
            ),
        ));
    }

    public function isCommunication(): bool
    {
        return $this->entry_type->isCommunication();
    }
}
