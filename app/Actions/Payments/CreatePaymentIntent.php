<?php

namespace App\Actions\Payments;

use App\Contracts\Payments\Payable;
use App\Enums\AccountStatus;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Models\Payment;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Payments\ExchangeRateResolver;
use App\Services\Payments\PaymentGatewayRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class CreatePaymentIntent
{
    public function __construct(
        private readonly PaymentGatewayRegistry $registry,
        private readonly ExchangeRateResolver $rates,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * Create (or return) the payment intent for a payable.
     *
     * The amount is read from the payable, never from the caller. A browser
     * cannot propose what it owes.
     *
     * @param  array<string, mixed>  $context  Payer details a gateway needs (phone, return URL).
     */
    public function execute(
        ?User $actor,
        Payable&Model $payable,
        PaymentProvider $provider,
        string $idempotencyKey,
        array $context = [],
    ): Payment {
        Validator::make(
            ['idempotency_key' => trim($idempotencyKey), 'provider' => $provider->value],
            [
                'idempotency_key' => ['required', 'uuid'],
                'provider' => ['required', Rule::enum(PaymentProvider::class)],
            ],
        )->validate();

        $this->assertProviderUsable($actor, $provider, $payable->payableCurrency());

        return DB::transaction(function () use ($actor, $payable, $provider, $idempotencyKey, $context): Payment {
            $lockedActor = $actor === null
                ? null
                : User::query()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();

            if ($lockedActor !== null && $lockedActor->status !== AccountStatus::Active) {
                throw new AuthorizationException;
            }

            // Lock the payable so its outstanding balance cannot change between
            // the check below and the intent being written.
            $lockedPayable = $payable->newQuery()
                ->whereKey($payable->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            // The re-read returns a bare Model, so re-establish the contract
            // rather than assuming it survived the round trip.
            if (! $lockedPayable instanceof Payable) {
                throw new RuntimeException('The locked record is no longer payable.');
            }

            $this->assertOwnership($lockedActor, $lockedPayable);

            $ownerHash = $this->ownerHash($lockedActor, $lockedPayable);
            $existing = Payment::query()
                ->where('idempotency_owner_hash', $ownerHash)
                ->where('idempotency_key', trim($idempotencyKey))
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                // Replaying the same key returns the original intent rather
                // than starting a second collection for the same money.
                return $existing;
            }

            if (! $lockedPayable->acceptsPayment()) {
                throw ValidationException::withMessages([
                    'payment' => 'This booking is not accepting payment.',
                ]);
            }

            $outstanding = $lockedPayable->outstandingAmountMinor();

            if ($outstanding < 1) {
                throw ValidationException::withMessages([
                    'payment' => 'This booking is already paid in full.',
                ]);
            }

            // An in-flight intent for the same payable blocks a second one, so
            // a customer cannot accidentally pay twice by opening two tabs.
            $inFlight = Payment::query()
                ->where('payable_type', $lockedPayable->getMorphClass())
                ->where('payable_id', $lockedPayable->getKey())
                ->inFlight()
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->lockForUpdate()
                ->first();

            if ($inFlight !== null) {
                return $inFlight;
            }

            $currency = strtoupper($lockedPayable->payableCurrency());
            $base = $this->rates->toBase($outstanding, $currency);
            $now = now();

            $payment = new Payment;
            $payment->forceFill([
                'reference' => 'PAY-'.Str::upper((string) Str::ulid()),
                'payable_type' => $lockedPayable->getMorphClass(),
                'payable_id' => $lockedPayable->getKey(),
                'customer_id' => $lockedPayable->payer()?->getKey(),
                'recorded_by_user_id' => $lockedActor?->canAccessAdministration() ? $lockedActor->getKey() : null,
                'provider' => $provider,
                'status' => PaymentStatus::Pending,
                'amount_minor' => $outstanding,
                'currency' => $currency,
                'base_amount_minor' => $base['amount_minor'],
                'base_currency' => $base['currency'],
                // Stamped now so a later rate change cannot rewrite this
                // payment's contribution to historic revenue.
                'exchange_rate_ppm' => $base['rate_ppm'],
                'idempotency_owner_hash' => $ownerHash,
                'idempotency_key' => trim($idempotencyKey),
                'expires_at' => $now->copy()->addMinutes(
                    (int) config('payments.intent_expiry_minutes', 60),
                ),
                'metadata' => $context === [] ? null : ['context' => $this->safeContext($context)],
            ])->save();

            $this->auditLogger->record(
                event: 'payment.intent_created',
                auditable: $payment,
                newValues: [
                    'reference' => $payment->reference,
                    'payable_type' => $payment->payable_type,
                    'payable_id' => $payment->payable_id,
                    'provider' => $provider->value,
                    'amount_minor' => $payment->amount_minor,
                    'currency' => $payment->currency,
                    'base_amount_minor' => $payment->base_amount_minor,
                    'status' => PaymentStatus::Pending->value,
                ],
                user: $lockedActor,
            );

            return $payment;
        }, 3);
    }

    private function assertProviderUsable(?User $actor, PaymentProvider $provider, string $currency): void
    {
        if (! $provider->isEnabled() || ! $this->registry->has($provider)) {
            throw ValidationException::withMessages([
                'provider' => 'That payment method is not available.',
            ]);
        }

        if (! $provider->supportsCurrency($currency)) {
            throw ValidationException::withMessages([
                'provider' => $provider->label().' cannot process '.$currency.' payments.',
            ]);
        }

        if (! $this->registry->for($provider)->isAvailable()) {
            throw ValidationException::withMessages([
                'provider' => $provider->label().' is temporarily unavailable.',
            ]);
        }

        // Cash represents money already handed over, so only an operator may
        // create one. A customer selecting it would be recording a fiction.
        if (! $provider->isCustomerSelectable()
            && ! ($actor !== null && $actor->canAccessAdministration())) {
            throw new AuthorizationException;
        }
    }

    private function assertOwnership(?User $actor, Payable&Model $payable): void
    {
        if ($actor === null) {
            // Guest checkout is permitted only for a payable with no owner.
            if ($payable->payer() !== null) {
                throw new AuthorizationException;
            }

            return;
        }

        if ($actor->canAccessAdministration()) {
            return;
        }

        $payer = $payable->payer();

        if (! $actor->hasRole(UserRole::Customer)
            || $payer === null
            || $payer->getKey() !== $actor->getKey()) {
            throw new AuthorizationException;
        }
    }

    private function ownerHash(?User $actor, Payable&Model $payable): string
    {
        $payer = $payable->payer();

        $owner = $payer !== null
            ? 'customer|'.$payer->getKey()
            : 'guest|'.$payable->getMorphClass().'|'.$payable->getKey();

        return hash_hmac('sha256', $owner, (string) config('app.key'));
    }

    /**
     * Strip anything that must not be persisted. Provider context can carry a
     * payer phone number; it never carries card data or a token.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function safeContext(array $context): array
    {
        $forbidden = ['card', 'card_number', 'pan', 'cvv', 'cvc', 'token', 'secret', 'password'];

        return array_filter(
            $context,
            static fn (string $key): bool => ! in_array(mb_strtolower($key), $forbidden, true),
            ARRAY_FILTER_USE_KEY,
        );
    }
}
