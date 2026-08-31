<?php

namespace App\Models;

use App\Contracts\Payments\Payable;
use App\Enums\VehicleImportBodyType;
use App\Enums\VehicleImportDriveType;
use App\Enums\VehicleImportEventType;
use App\Enums\VehicleImportFuelType;
use App\Enums\VehicleImportStatus;
use App\Enums\VehicleImportSteering;
use App\Enums\VehicleImportTransmission;
use App\Models\Concerns\IsPayable;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * @property VehicleImportStatus $status
 * @property VehicleImportBodyType $body_type
 * @property VehicleImportFuelType $fuel_type
 * @property VehicleImportTransmission $transmission
 * @property VehicleImportDriveType $drive_type
 * @property VehicleImportSteering $steering
 * @property string $reference
 * @property string $contact_name
 * @property string $contact_email
 * @property string $contact_phone
 * @property int $budget_minor
 * @property string $budget_currency
 * @property int|null $total_price_minor
 * @property int|null $deposit_minor
 * @property string|null $quote_currency
 * @property int $units
 * @property CarbonImmutable|null $quoted_at
 * @property CarbonImmutable|null $quote_expires_at
 * @property CarbonImmutable|null $delivered_at
 * @property User|null $customer
 */
class VehicleImportOrder extends Model implements Payable
{
    use HasFactory;
    use IsPayable;

    protected $fillable = [
        'reference', 'tracking_token', 'customer_id', 'assigned_to_user_id', 'status',
        'make', 'model', 'year_from', 'year_to', 'body_type', 'fuel_type', 'transmission',
        'drive_type', 'steering', 'engine_capacity_cc', 'origin_country', 'maximum_mileage_km',
        'auction_grade', 'preferred_colour', 'units', 'purpose', 'notes',
        'budget_minor', 'budget_currency',
        'total_price_minor', 'deposit_minor', 'quote_currency', 'quoted_at', 'quote_expires_at',
        'estimated_arrival_on', 'chassis_number', 'actual_mileage_km', 'actual_colour',
        'vessel_name', 'bill_of_lading',
        'contact_name', 'contact_email', 'contact_phone',
        'idempotency_owner_hash', 'idempotency_key',
        'cancellation_reason', 'internal_notes', 'delivered_at', 'cancelled_at',
    ];

    /**
     * The tracking token is the guest's only credential — it must never be
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
            'status' => VehicleImportStatus::class,
            'body_type' => VehicleImportBodyType::class,
            'fuel_type' => VehicleImportFuelType::class,
            'transmission' => VehicleImportTransmission::class,
            'drive_type' => VehicleImportDriveType::class,
            'steering' => VehicleImportSteering::class,
            'year_from' => 'integer',
            'year_to' => 'integer',
            'engine_capacity_cc' => 'integer',
            'maximum_mileage_km' => 'integer',
            'actual_mileage_km' => 'integer',
            'units' => 'integer',
            'budget_minor' => 'integer',
            'total_price_minor' => 'integer',
            'deposit_minor' => 'integer',
            'quoted_at' => 'immutable_datetime',
            'quote_expires_at' => 'immutable_datetime',
            'estimated_arrival_on' => 'immutable_date',
            'delivered_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'chassis_number' => 'encrypted',
        ];
    }

    protected function contactEmail(): Attribute
    {
        return Attribute::make(set: static fn (mixed $v): string => mb_strtolower(trim((string) $v)));
    }

    protected function originCountry(): Attribute
    {
        return Attribute::make(set: static fn (mixed $v): string => strtoupper(trim((string) $v)));
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

    public function events(): HasMany
    {
        return $this->hasMany(VehicleImportEvent::class)->orderByDesc('created_at')->orderByDesc('id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(VehicleImportMessage::class)->orderBy('created_at')->orderBy('id');
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function scopeForCustomer(Builder $query, User|int $customer): Builder
    {
        return $query->where('customer_id', $customer instanceof User ? $customer->getKey() : $customer);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', VehicleImportStatus::openValues());
    }

    public function scopeSearch(Builder $query, string $search): Builder
    {
        $search = trim($search);

        if ($search === '') {
            return $query;
        }

        return $query->where(function (Builder $m) use ($search): void {
            $m->where('reference', 'like', '%'.$search.'%')
                ->orWhere('contact_name', 'like', '%'.$search.'%')
                ->orWhere('contact_email', 'like', '%'.$search.'%')
                ->orWhere('make', 'like', '%'.$search.'%')
                ->orWhere('model', 'like', '%'.$search.'%');
        });
    }

    public function canTransitionTo(VehicleImportStatus $next): bool
    {
        return $this->status->canTransitionTo($next);
    }

    public function isGuest(): bool
    {
        return $this->customer_id === null;
    }

    public function hasQuote(): bool
    {
        return $this->total_price_minor !== null
            && $this->deposit_minor !== null
            && $this->quote_currency !== null;
    }

    public function quoteHasExpired(?\DateTimeInterface $at = null): bool
    {
        return $this->quote_expires_at !== null
            && ! $this->quote_expires_at->isAfter($at ?? now());
    }

    public function vehicleSummary(): string
    {
        $years = $this->year_from === $this->year_to
            ? (string) $this->year_from
            : $this->year_from.'–'.$this->year_to;

        return trim($years.' '.$this->make.' '.$this->model);
    }

    // ---- Payable ---------------------------------------------------------

    public function paymentDescription(): string
    {
        $units = $this->units > 1 ? ' ×'.$this->units : '';

        return $this->vehicleSummary().$units.' — '.$this->nextPaymentLabel();
    }

    public function payableCurrency(): string
    {
        // Before a quote exists there is nothing to pay, but the contract must
        // still return a currency; the budget currency is the honest default.
        return strtoupper((string) ($this->quote_currency ?? $this->budget_currency));
    }

    public function payableAmountMinor(): int
    {
        return (int) ($this->total_price_minor ?? 0);
    }

    /**
     * Imports are paid in two stages, so "outstanding" means *currently due*,
     * not the whole remaining balance.
     *
     * Before the deposit is settled only the deposit is collectable — asking a
     * customer for the full price up front would be wrong, and asking for the
     * balance before the vehicle is ready would be worse.
     */
    public function outstandingAmountMinor(): int
    {
        // A cancelled import owes nothing. This must agree with
        // acceptsPayment(): if they disagree, a screen can show an amount due
        // for something the checkout would refuse to charge.
        if (! $this->hasQuote() || $this->status === VehicleImportStatus::Cancelled) {
            return 0;
        }

        $settled = $this->settledAmountMinor();
        $deposit = (int) $this->deposit_minor;
        $total = (int) $this->total_price_minor;

        if ($settled < $deposit) {
            return $deposit - $settled;
        }

        // The balance only becomes collectable once the vehicle is ready.
        if ($this->status !== VehicleImportStatus::ReadyForDelivery) {
            return 0;
        }

        return max(0, $total - $settled);
    }

    public function nextPaymentLabel(): string
    {
        if (! $this->hasQuote()) {
            return 'Awaiting quotation';
        }

        return $this->settledAmountMinor() < (int) $this->deposit_minor
            ? 'Deposit'
            : 'Balance';
    }

    public function balanceMinor(): int
    {
        if (! $this->hasQuote()) {
            return 0;
        }

        return max(0, (int) $this->total_price_minor - (int) $this->deposit_minor);
    }

    public function depositIsSettled(): bool
    {
        return $this->hasQuote() && $this->settledAmountMinor() >= (int) $this->deposit_minor;
    }

    public function acceptsPayment(): bool
    {
        if (! $this->hasQuote() || $this->status === VehicleImportStatus::Cancelled) {
            return false;
        }

        // An expired quotation must be re-issued before any more money moves.
        if (! $this->depositIsSettled() && $this->quoteHasExpired()) {
            return false;
        }

        return $this->outstandingAmountMinor() > 0;
    }

    /**
     * Runs inside the settlement transaction. Safe to call twice: both markers
     * use firstOrCreate, so a duplicate webhook cannot double-record a stage.
     */
    public function applySettledPayment(Payment $payment): void
    {
        $settled = $this->settledAmountMinor();

        if ($settled >= (int) $this->deposit_minor) {
            $this->recordEvent(
                VehicleImportEventType::DepositSettled,
                'Deposit received.',
                ['payment_reference' => $payment->reference],
            );
        }

        if ($this->total_price_minor !== null && $settled >= (int) $this->total_price_minor) {
            $this->recordEvent(
                VehicleImportEventType::BalanceSettled,
                'Balance received in full.',
                ['payment_reference' => $payment->reference],
            );

            $this->recordEvent(
                VehicleImportEventType::LoyaltyEligible,
                'Import paid in full.',
                [
                    'schema_version' => 1,
                    'order_id' => $this->getKey(),
                    'order_reference' => $this->reference,
                    'customer_id' => $this->customer_id,
                    'total_minor' => (int) $this->total_price_minor,
                    'currency' => $this->payableCurrency(),
                    'payment_reference' => $payment->reference,
                ],
                customerVisible: false,
            );
        }
    }

    public function paymentReturnUrl(): string
    {
        if (! $this->isGuest()) {
            return route('portal.vehicle-imports.show', ['customerVehicleImport' => $this->reference]);
        }

        return route('vehicle-imports.track', ['token' => $this->tracking_token]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function recordEvent(
        VehicleImportEventType $type,
        string $summary,
        array $payload = [],
        ?User $actor = null,
        bool $customerVisible = true,
    ): VehicleImportEvent {
        return VehicleImportEvent::query()->firstOrCreate(
            [
                'vehicle_import_order_id' => $this->getKey(),
                'event_type' => $type->value,
                'summary' => $summary,
            ],
            [
                'actor_user_id' => $actor?->getKey(),
                'is_customer_visible' => $customerVisible,
                'payload' => $payload === [] ? null : $payload,
            ],
        );
    }

    public function formattedBudget(): string
    {
        return Money::format($this->budget_minor, $this->budget_currency);
    }
}
