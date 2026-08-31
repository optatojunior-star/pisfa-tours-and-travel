<?php

namespace App\Models;

use App\Enums\FlightInquiryScope;
use App\Enums\FlightInquiryStatus;
use App\Enums\FlightTravelClass;
use App\Enums\FlightTripType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FlightInquiry extends Model
{
    use HasFactory;

    protected $fillable = [
        'reference',
        'customer_id',
        'idempotency_owner_hash',
        'idempotency_key',
        'request_fingerprint',
        'status',
        'scope',
        'trip_type',
        'travel_class',
        'origin',
        'destination',
        'outbound_on',
        'return_on',
        'passenger_count',
        'contact_name',
        'contact_email',
        'contact_phone',
        'notes',
        'assigned_to_user_id',
        'assigned_at',
        'resolution_reason',
        'acknowledged_at',
        'first_contacted_at',
        'booked_at',
        'closed_at',
        'cancelled_at',
        'reopened_at',
        'reopen_count',
    ];

    protected $hidden = [
        'idempotency_owner_hash',
        'idempotency_key',
        'request_fingerprint',
    ];

    protected function casts(): array
    {
        return [
            'status' => FlightInquiryStatus::class,
            'scope' => FlightInquiryScope::class,
            'trip_type' => FlightTripType::class,
            'travel_class' => FlightTravelClass::class,
            'outbound_on' => 'immutable_date',
            'return_on' => 'immutable_date',
            'passenger_count' => 'integer',
            'assigned_at' => 'immutable_datetime',
            'acknowledged_at' => 'immutable_datetime',
            'first_contacted_at' => 'immutable_datetime',
            'booked_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'reopened_at' => 'immutable_datetime',
            'reopen_count' => 'integer',
        ];
    }

    protected function contactEmail(): Attribute
    {
        return Attribute::make(
            set: static fn (mixed $value): string => mb_strtolower(trim((string) $value)),
        );
    }

    public function getRouteKeyName(): string
    {
        return 'reference';
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(FlightInquiryEntry::class)
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    public function scopeForCustomer(Builder $query, User|int $customer): Builder
    {
        return $query->where('customer_id', $customer instanceof User ? $customer->getKey() : $customer);
    }

    public function scopeWithStatus(Builder $query, FlightInquiryStatus $status): Builder
    {
        return $query->where('status', $status->value);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', FlightInquiryStatus::openValues());
    }

    public function scopeUnassigned(Builder $query): Builder
    {
        return $query->whereNull('assigned_to_user_id');
    }

    public function scopeSearch(Builder $query, string $search): Builder
    {
        $search = trim($search);

        if ($search === '') {
            return $query;
        }

        return $query->where(function (Builder $matches) use ($search): void {
            $matches
                ->where('reference', 'like', '%'.$search.'%')
                ->orWhere('contact_name', 'like', '%'.$search.'%')
                ->orWhere('contact_email', 'like', '%'.$search.'%')
                ->orWhere('contact_phone', 'like', '%'.$search.'%')
                ->orWhere('origin', 'like', '%'.$search.'%')
                ->orWhere('destination', 'like', '%'.$search.'%');
        });
    }

    public function canTransitionTo(FlightInquiryStatus $next): bool
    {
        return $this->status->canTransitionTo($next);
    }

    public function isGuest(): bool
    {
        return $this->customer_id === null;
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    public function routeLabel(): string
    {
        return $this->origin.' → '.$this->destination;
    }
}
