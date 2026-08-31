<?php

namespace App\Models;

use App\Enums\TourTravelerType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TourTraveler extends Model
{
    use HasFactory;

    protected $fillable = [
        'tour_booking_id',
        'full_name',
        'traveler_type',
        'date_of_birth',
        'nationality',
        'dietary_notes',
        'accessibility_notes',
        'is_lead',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'traveler_type' => TourTravelerType::class,
            'date_of_birth' => 'immutable_date',
            'is_lead' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(TourBooking::class, 'tour_booking_id');
    }
}
