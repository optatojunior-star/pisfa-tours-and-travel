<?php

namespace App\Models;

use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property PaymentStatus $status
 * @property PaymentProvider $provider
 * @property int $amount_minor
 * @property int $base_amount_minor
 * @property int $exchange_rate_ppm
 * @property int $refunded_amount_minor
 * @property string $currency
 * @property string $base_currency
 * @property array<string, mixed>|null $metadata
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $initiated_at
 * @property CarbonImmutable|null $paid_at
 * @property CarbonImmutable|null $failed_at
 * @property CarbonImmutable|null $cancelled_at
 * @property User|null $customer
 */
class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'reference',
        'payable_type',
        'payable_id',
        'customer_id',
        'recorded_by_user_id',
        'provider',
        'status',
        'amount_minor',
        'currency',
        'base_amount_minor',
        'base_currency',
        'exchange_rate_ppm',
        'refunded_amount_minor',
        'provider_reference',
        'provider_transaction_id',
        'idempotency_owner_hash',
        'idempotency_key',
        'failure_reason',
        'metadata',
        'expires_at',
        'initiated_at',
        'paid_at',
        'failed_at',
        'cancelled_at',
    ];

    /**
     * Idempotency material and provider identifiers are internal. Exposing them
     * would let a client forge a duplicate-suppressing request or probe for
     * another customer's transaction.
     */
    protected $hidden = [
        'idempotency_owner_hash',
        'idempotency_key',
        'provider_reference',
        'provider_transaction_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'provider' => PaymentProvider::class,
            'amount_minor' => 'integer',
            'base_amount_minor' => 'integer',
            'exchange_rate_ppm' => 'integer',
            'refunded_amount_minor' => 'integer',
            'metadata' => 'array',
            'expires_at' => 'immutable_datetime',
            'initiated_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    protected function currency(): Attribute
    {
        return Attribute::make(set: static fn (mixed $v): string => strtoupper(trim((string) $v)));
    }

    protected function baseCurrency(): Attribute
    {
        return Attribute::make(set: static fn (mixed $v): string => strtoupper(trim((string) $v)));
    }

    public function getRouteKeyName(): string
    {
        return 'reference';
    }

    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class)->orderByDesc('id');
    }

    public function webhookEvents(): HasMany
    {
        return $this->hasMany(PaymentWebhookEvent::class);
    }

    public function scopeForCustomer(Builder $query, User|int $customer): Builder
    {
        return $query->where('customer_id', $customer instanceof User ? $customer->getKey() : $customer);
    }

    public function scopeSettled(Builder $query): Builder
    {
        return $query->whereIn('status', PaymentStatus::settledValues());
    }

    public function scopeInFlight(Builder $query): Builder
    {
        return $query->whereIn('status', PaymentStatus::inFlightValues());
    }

    public function scopeWithStatus(Builder $query, PaymentStatus $status): Builder
    {
        return $query->where('status', $status->value);
    }

    public function scopeExpirable(Builder $query, ?\DateTimeInterface $at = null): Builder
    {
        return $query
            ->whereIn('status', PaymentStatus::inFlightValues())
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $at ?? now());
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
                ->orWhere('provider_transaction_id', 'like', '%'.$search.'%')
                ->orWhereHas('customer', fn (Builder $c) => $c
                    ->where('name', 'like', '%'.$search.'%')
                    ->orWhere('email', 'like', '%'.$search.'%'));
        });
    }

    public function canTransitionTo(PaymentStatus $next): bool
    {
        return $this->status->canTransitionTo($next);
    }

    public function isSettled(): bool
    {
        return $this->status->isSettled();
    }

    /**
     * Amount still refundable: what was collected, less completed refunds and
     * anything already claimed by an in-flight refund request.
     */
    public function refundableAmountMinor(): int
    {
        if (! $this->status->isRefundable()) {
            return 0;
        }

        $claimed = (int) $this->refunds()
            ->whereIn('status', RefundStatus::claimingValues())
            ->sum('amount_minor');

        return max(0, $this->amount_minor - $claimed);
    }

    /** Collected amount net of completed refunds — the figure revenue reports use. */
    public function netAmountMinor(): int
    {
        return max(0, $this->amount_minor - $this->refunded_amount_minor);
    }

    public function isExpired(?\DateTimeInterface $at = null): bool
    {
        return $this->expires_at !== null
            && $this->status->isInFlight()
            && ! $this->expires_at->isAfter($at ?? now());
    }

    public function formattedAmount(): string
    {
        return Money::format($this->amount_minor, $this->currency);
    }

    public function formattedNetAmount(): string
    {
        return Money::format($this->netAmountMinor(), $this->currency);
    }
}
