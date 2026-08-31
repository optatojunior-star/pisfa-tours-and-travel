<?php

namespace App\Models;

use App\Enums\SalesEnquiryStatus;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Somebody asking about a car in the showroom.
 *
 * May be raised by a guest, so `customer_id` is nullable throughout.
 *
 * @property SalesEnquiryStatus $status
 * @property string $reference
 * @property string $contact_name
 * @property string $contact_email
 * @property string $contact_phone
 * @property int|null $customer_id
 * @property int|null $offer_minor
 * @property string|null $offer_currency
 * @property int|null $vehicle_listing_id
 * @property int|null $assigned_to_user_id
 * @property string|null $message
 * @property string|null $internal_notes
 * @property string|null $closure_reason
 * @property CarbonImmutable|null $closed_at
 * @property CarbonImmutable $created_at
 * @property VehicleListing|null $listing
 * @property User|null $customer
 * @property User|null $assignee
 */
class VehicleSalesEnquiry extends Model
{
    use HasFactory;

    protected $fillable = [
        'reference',
        'vehicle_listing_id',
        'customer_id',
        'assigned_to_user_id',
        'status',
        'contact_name',
        'contact_email',
        'contact_phone',
        'message',
        'offer_minor',
        'offer_currency',
        'internal_notes',
        'closure_reason',
        'closed_at',
        'idempotency_owner_hash',
        'idempotency_key',
    ];

    /** Idempotency material and internal notes are never serialised. */
    protected $hidden = [
        'idempotency_owner_hash',
        'idempotency_key',
        'internal_notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => SalesEnquiryStatus::class,
            'offer_minor' => 'integer',
            'closed_at' => 'immutable_datetime',
        ];
    }

    protected function contactEmail(): Attribute
    {
        return Attribute::make(set: static fn (mixed $v): string => mb_strtolower(trim((string) $v)));
    }

    public function getRouteKeyName(): string
    {
        return 'reference';
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(VehicleListing::class, 'vehicle_listing_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function scopeForCustomer(Builder $query, User|int $customer): Builder
    {
        return $query->where('customer_id', $customer instanceof User ? $customer->getKey() : $customer);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', SalesEnquiryStatus::openValues());
    }

    public function scopeSearch(Builder $query, string $search): Builder
    {
        $search = trim($search);

        if ($search === '') {
            return $query;
        }

        return $query->where(function (Builder $nested) use ($search): void {
            $nested->where('reference', 'like', '%'.$search.'%')
                ->orWhere('contact_name', 'like', '%'.$search.'%')
                ->orWhere('contact_email', 'like', '%'.$search.'%');
        });
    }

    public function isGuest(): bool
    {
        return $this->customer_id === null;
    }

    public function canTransitionTo(SalesEnquiryStatus $next): bool
    {
        return $this->status->canTransitionTo($next);
    }

    public function formattedOffer(): ?string
    {
        if ($this->offer_minor === null || $this->offer_currency === null) {
            return null;
        }

        return Money::format($this->offer_minor, $this->offer_currency);
    }
}
