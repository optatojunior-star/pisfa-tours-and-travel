<?php

namespace App\Models;

use App\Contracts\Payments\Payable;
use App\Enums\DocumentCategory;
use App\Enums\InvoiceStatus;
use App\Models\Concerns\HasBillingTotals;
use App\Models\Concerns\IsPayable;
use App\Support\Money;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A receivable. The only billing document money is ever collected against.
 *
 * @property InvoiceStatus $status
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
 * @property int|null $customer_id
 * @property int|null $quotation_id
 * @property CarbonImmutable|null $issued_on
 * @property CarbonImmutable|null $due_on
 * @property CarbonImmutable|null $issued_at
 * @property CarbonImmutable|null $paid_at
 * @property User|null $customer
 * @property Quotation|null $quotation
 */
class Invoice extends Model implements Payable
{
    use HasBillingTotals;
    use HasFactory;
    use IsPayable;

    protected $fillable = [
        'number',
        'tracking_token',
        'quotation_id',
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
        'issued_on',
        'due_on',
        'terms',
        'notes',
        'internal_notes',
        'issued_at',
        'paid_at',
        'cancelled_at',
        'voided_at',
        'closure_reason',
    ];

    protected $hidden = ['tracking_token', 'internal_notes'];

    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,
            'subtotal_minor' => 'integer',
            'discount_minor' => 'integer',
            'tax_rate_bps' => 'integer',
            'tax_amount_minor' => 'integer',
            'total_minor' => 'integer',
            'deposit_minor' => 'integer',
            'issued_on' => 'immutable_date',
            'due_on' => 'immutable_date',
            'issued_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'voided_at' => 'immutable_datetime',
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

    /** @return HasMany<InvoiceItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    /** @return MorphMany<Document, $this> */
    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable')
            ->where('category', DocumentCategory::Invoice->value);
    }

    public function scopeForCustomer(Builder $query, User|int $customer): Builder
    {
        return $query->where('customer_id', $customer instanceof User ? $customer->getKey() : $customer);
    }

    /** A draft invoice is internal and must never reach a portal listing. */
    public function scopeVisibleToCustomer(Builder $query): Builder
    {
        return $query->whereIn('status', InvoiceStatus::customerVisibleValues());
    }

    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->whereIn('status', InvoiceStatus::outstandingValues());
    }

    /**
     * Past the due date with money still owing. Derived rather than stored, so
     * it can never disagree with the payments actually received.
     */
    public function scopeOverdue(Builder $query, ?DateTimeInterface $at = null): Builder
    {
        return $query
            ->whereIn('status', InvoiceStatus::outstandingValues())
            ->whereNotNull('due_on')
            ->whereDate('due_on', '<', ($at ?? now())->format('Y-m-d'));
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

    public function canTransitionTo(InvoiceStatus $next): bool
    {
        return $this->status->canTransitionTo($next);
    }

    public function isGuest(): bool
    {
        return $this->customer_id === null;
    }

    public function isOverdue(?DateTimeInterface $at = null): bool
    {
        return $this->status->isOutstanding()
            && $this->due_on !== null
            && $this->due_on->endOfDay()->isBefore($at ?? now());
    }

    public function daysOverdue(?DateTimeInterface $at = null): int
    {
        if (! $this->isOverdue($at) || $this->due_on === null) {
            return 0;
        }

        return (int) $this->due_on->endOfDay()->diffInDays($at ?? now());
    }

    public function currentDocument(): ?Document
    {
        return $this->documents()->where('is_current', true)->first();
    }

    public function formattedOutstanding(): string
    {
        return Money::format($this->outstandingAmountMinor(), $this->currency);
    }

    public function formattedSettled(): string
    {
        return Money::format($this->settledAmountMinor(), $this->currency);
    }

    public function viewUrl(): string
    {
        return $this->isGuest()
            ? route('invoices.track', ['token' => $this->tracking_token])
            : route('portal.invoices.show', ['customerInvoice' => $this->number]);
    }

    // ---- Payable ---------------------------------------------------------

    public function paymentReference(): string
    {
        return $this->number;
    }

    public function paymentDescription(): string
    {
        return $this->title.' — invoice '.$this->number;
    }

    public function payableAmountMinor(): int
    {
        return (int) $this->total_minor;
    }

    public function hasDeposit(): bool
    {
        return $this->deposit_minor !== null
            && $this->deposit_minor > 0
            && $this->deposit_minor < (int) $this->total_minor;
    }

    public function depositIsSettled(): bool
    {
        return ! $this->hasDeposit() || $this->settledAmountMinor() >= (int) $this->deposit_minor;
    }

    /**
     * What is *currently due*, not the whole remaining balance.
     *
     * A deposit invoice collects in two stages, so asking for the full amount
     * before the deposit has been taken would charge more than was agreed.
     *
     * A draft, cancelled, or voided invoice owes nothing. This must agree with
     * acceptsPayment(): if the two disagree, a screen can show an amount due for
     * something checkout would then refuse to charge.
     */
    public function outstandingAmountMinor(): int
    {
        if (! $this->status->collectsPayment()) {
            return 0;
        }

        $settled = $this->settledAmountMinor();

        if ($this->hasDeposit() && $settled < (int) $this->deposit_minor) {
            return (int) $this->deposit_minor - $settled;
        }

        return max(0, $this->payableAmountMinor() - $settled);
    }

    /** The whole remaining balance, regardless of staging. */
    public function remainingBalanceMinor(): int
    {
        if (! $this->status->collectsPayment()) {
            return 0;
        }

        return max(0, $this->payableAmountMinor() - $this->settledAmountMinor());
    }

    public function nextPaymentLabel(): string
    {
        return $this->hasDeposit() && ! $this->depositIsSettled() ? 'Deposit' : 'Balance';
    }

    public function acceptsPayment(): bool
    {
        return $this->status->collectsPayment() && $this->outstandingAmountMinor() > 0;
    }

    /**
     * Runs inside the settlement transaction. Safe to call twice: the status is
     * derived from the settled total, so a duplicate webhook recomputes the same
     * answer rather than advancing a step.
     */
    public function applySettledPayment(Payment $payment): void
    {
        $settled = $this->settledAmountMinor();
        $total = (int) $this->total_minor;

        if ($settled <= 0 || ! $this->status->collectsPayment()) {
            return;
        }

        $next = $settled >= $total ? InvoiceStatus::Paid : InvoiceStatus::PartiallyPaid;

        if ($this->status === $next) {
            return;
        }

        $this->forceFill([
            'status' => $next,
            'paid_at' => $next === InvoiceStatus::Paid ? ($this->paid_at ?? now()) : null,
        ])->save();
    }

    public function paymentReturnUrl(): string
    {
        return $this->viewUrl();
    }
}
