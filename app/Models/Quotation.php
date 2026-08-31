<?php

namespace App\Models;

use App\Enums\DocumentCategory;
use App\Enums\QuotationStatus;
use App\Models\Concerns\HasBillingTotals;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A priced offer with an expiry date.
 *
 * A quotation is not payable: money is only collected against the invoice that
 * an accepted quotation produces. Keeping them separate is what lets an offer
 * expire or be declined without ever touching a receivable.
 *
 * @property QuotationStatus $status
 * @property string $number
 * @property string $title
 * @property string $currency
 * @property string $contact_name
 * @property string $contact_email
 * @property string $contact_phone
 * @property string $tracking_token
 * @property int $subtotal_minor
 * @property int $discount_minor
 * @property int $tax_rate_bps
 * @property int $tax_amount_minor
 * @property int $total_minor
 * @property int|null $deposit_minor
 * @property int $revision
 * @property int|null $customer_id
 * @property CarbonImmutable|null $valid_until
 * @property CarbonImmutable|null $sent_at
 * @property CarbonImmutable|null $accepted_at
 * @property CarbonImmutable|null $declined_at
 * @property User|null $customer
 * @property Invoice|null $invoice
 * @property QuotationRequest|null $request
 */
class Quotation extends Model
{
    use HasBillingTotals;
    use HasFactory;

    protected $fillable = [
        'number',
        'tracking_token',
        'quotation_request_id',
        'customer_id',
        'created_by_user_id',
        'status',
        'contact_name',
        'contact_email',
        'contact_phone',
        'company_name',
        'title',
        'currency',
        'subtotal_minor',
        'discount_minor',
        'tax_rate_bps',
        'tax_amount_minor',
        'total_minor',
        'deposit_minor',
        'valid_until',
        'terms',
        'notes',
        'internal_notes',
        'revision',
        'sent_at',
        'accepted_at',
        'declined_at',
        'decline_reason',
        'expired_at',
        'cancelled_at',
        'cancellation_reason',
    ];

    protected $hidden = ['tracking_token', 'internal_notes'];

    protected function casts(): array
    {
        return [
            'status' => QuotationStatus::class,
            'subtotal_minor' => 'integer',
            'discount_minor' => 'integer',
            'tax_rate_bps' => 'integer',
            'tax_amount_minor' => 'integer',
            'total_minor' => 'integer',
            'deposit_minor' => 'integer',
            'revision' => 'integer',
            'valid_until' => 'immutable_date',
            'sent_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'declined_at' => 'immutable_datetime',
            'expired_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    protected function contactEmail(): Attribute
    {
        return Attribute::make(set: static fn (mixed $v): string => mb_strtolower(trim((string) $v)));
    }

    public function getRouteKeyName(): string
    {
        return 'number';
    }

    /** @return HasMany<QuotationItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(QuotationItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(QuotationRequest::class, 'quotation_request_id');
    }

    /** @return HasOne<Invoice, $this> */
    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class);
    }

    /** @return MorphMany<Document, $this> */
    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable')
            ->where('category', DocumentCategory::Quotation->value);
    }

    public function scopeForCustomer(Builder $query, User|int $customer): Builder
    {
        return $query->where('customer_id', $customer instanceof User ? $customer->getKey() : $customer);
    }

    /**
     * The only scope a customer-facing surface may use. A draft is internal and
     * must never appear in a portal listing.
     */
    public function scopeVisibleToCustomer(Builder $query): Builder
    {
        return $query->whereIn('status', QuotationStatus::customerVisibleValues());
    }

    public function scopeSearch(Builder $query, string $search): Builder
    {
        $search = trim($search);

        if ($search === '') {
            return $query;
        }

        return $query->where(function (Builder $nested) use ($search): void {
            $nested->where('number', 'like', '%'.$search.'%')
                ->orWhere('title', 'like', '%'.$search.'%')
                ->orWhere('contact_name', 'like', '%'.$search.'%')
                ->orWhere('contact_email', 'like', '%'.$search.'%');
        });
    }

    public function canTransitionTo(QuotationStatus $next): bool
    {
        return $this->status->canTransitionTo($next);
    }

    public function isGuest(): bool
    {
        return $this->customer_id === null;
    }

    public function hasExpired(?DateTimeInterface $at = null): bool
    {
        if ($this->valid_until === null) {
            return false;
        }

        // Valid *through* the whole of valid_until, in Kampala terms.
        return $this->valid_until->endOfDay()->isBefore($at ?? now());
    }

    /**
     * Whether the customer can act on it right now. An offer past its validity
     * date is not acceptable even before the sweep has relabelled it, so a
     * customer can never accept an expired price.
     */
    public function awaitsResponse(?DateTimeInterface $at = null): bool
    {
        return $this->status->awaitsCustomer() && ! $this->hasExpired($at);
    }

    public function currentDocument(): ?Document
    {
        return $this->documents()->where('is_current', true)->first();
    }

    /** Where the recipient reads it: portal, or the guest token link. */
    public function viewUrl(): string
    {
        return $this->isGuest()
            ? route('quotations.track', ['token' => $this->tracking_token])
            : route('portal.quotations.show', ['customerQuotation' => $this->number]);
    }
}
