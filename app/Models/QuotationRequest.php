<?php

namespace App\Models;

use App\Enums\QuotationRequestStatus;
use App\Support\Money;
use App\Support\ServiceCatalogue;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A "please quote me" enquiry from the public site. May be raised by a guest,
 * so `customer_id` is nullable throughout — there is no placeholder account.
 *
 * @property QuotationRequestStatus $status
 * @property string $reference
 * @property string $service
 * @property string $contact_name
 * @property string $contact_email
 * @property string $contact_phone
 * @property string $tracking_token
 * @property int|null $customer_id
 * @property int|null $budget_minor
 * @property string|null $budget_currency
 * @property CarbonImmutable|null $closed_at
 * @property User|null $customer
 */
class QuotationRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'reference',
        'tracking_token',
        'customer_id',
        'assigned_to_user_id',
        'status',
        'service',
        'contact_name',
        'contact_email',
        'contact_phone',
        'company_name',
        'details',
        'preferred_date',
        'party_size',
        'budget_minor',
        'budget_currency',
        'internal_notes',
        'closure_reason',
        'closed_at',
        'idempotency_owner_hash',
        'idempotency_key',
    ];

    /**
     * The tracking token is the guest's only credential and must never be
     * serialised. Idempotency material and internal notes are equally internal.
     */
    protected $hidden = [
        'tracking_token',
        'idempotency_owner_hash',
        'idempotency_key',
        'internal_notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => QuotationRequestStatus::class,
            'preferred_date' => 'immutable_date',
            'party_size' => 'integer',
            'budget_minor' => 'integer',
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

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    /** @return HasMany<Quotation, $this> */
    public function quotations(): HasMany
    {
        return $this->hasMany(Quotation::class)->latest('id');
    }

    public function scopeForCustomer(Builder $query, User|int $customer): Builder
    {
        return $query->where('customer_id', $customer instanceof User ? $customer->getKey() : $customer);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', QuotationRequestStatus::openValues());
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
                ->orWhere('contact_email', 'like', '%'.$search.'%')
                ->orWhere('company_name', 'like', '%'.$search.'%');
        });
    }

    public function isGuest(): bool
    {
        return $this->customer_id === null;
    }

    public function canTransitionTo(QuotationRequestStatus $next): bool
    {
        return $this->status->canTransitionTo($next);
    }

    public function formattedBudget(): ?string
    {
        if ($this->budget_minor === null || $this->budget_currency === null) {
            return null;
        }

        return Money::format($this->budget_minor, $this->budget_currency);
    }

    public function serviceLabel(): string
    {
        return ServiceCatalogue::label($this->service);
    }

    /** Where the requester follows their enquiry: portal, or guest token. */
    public function trackingUrl(): string
    {
        return $this->isGuest()
            ? route('quotation-requests.track', ['token' => $this->tracking_token])
            : route('portal.quotation-requests.show', ['customerQuotationRequest' => $this->reference]);
    }
}
