<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CarHireContract extends Model
{
    use HasFactory;

    protected $fillable = [
        'car_hire_booking_id',
        'contract_number',
        'version',
        'template_version',
        'snapshot',
        'terms_snapshot',
        'content_sha256',
        'issued_at',
        'accepted_at',
        'accepted_by_user_id',
        'acceptance_ip',
        'acceptance_user_agent',
        'voided_at',
        'voided_by_user_id',
        'void_reason',
    ];

    protected $hidden = [
        'snapshot',
        'terms_snapshot',
        'content_sha256',
        'acceptance_ip',
        'acceptance_user_agent',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'snapshot' => 'array',
            'issued_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'voided_at' => 'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'contract_number';
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(CarHireBooking::class, 'car_hire_booking_id');
    }

    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by_user_id');
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by_user_id');
    }

    public function scopeNonVoided(Builder $query): Builder
    {
        return $query->whereNull('voided_at');
    }

    public function scopeAccepted(Builder $query): Builder
    {
        return $query->whereNotNull('accepted_at')->nonVoided();
    }

    public function scopeLatestVersion(Builder $query): Builder
    {
        return $query->orderByDesc('version')->orderByDesc('id');
    }

    public function isAccepted(): bool
    {
        return $this->accepted_at !== null && ! $this->isVoided();
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }
}
