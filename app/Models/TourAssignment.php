<?php

namespace App\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TourAssignment extends Model
{
    use HasFactory;

    protected $fillable = [
        'tour_booking_id',
        'driver_user_id',
        'assigned_by_user_id',
        'starts_at',
        'ends_at',
        'assigned_at',
        'unassigned_at',
        'unassigned_by_user_id',
        'unassignment_reason',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'assigned_at' => 'immutable_datetime',
            'unassigned_at' => 'immutable_datetime',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(TourBooking::class, 'tour_booking_id');
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_user_id');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_user_id');
    }

    public function unassignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'unassigned_by_user_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('unassigned_at');
    }

    public function scopeForDriver(Builder $query, User|int $driver): Builder
    {
        $driverId = $driver instanceof User ? $driver->getKey() : $driver;

        return $query->where('driver_user_id', $driverId);
    }

    public function scopeOverlapping(Builder $query, DateTimeInterface $startsAt, DateTimeInterface $endsAt): Builder
    {
        return $query
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt);
    }

    public function isActive(): bool
    {
        return $this->unassigned_at === null;
    }
}
