<?php

namespace App\Actions\Payments;

use App\Contracts\Payments\Payable;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Marks a payment settled, allocates it, and tells the payable.
 *
 * This is the only path money becomes real. It is called by webhook handling,
 * by return-URL verification, and by an administrator recording a manual
 * receipt — all three converge here so the guarantees cannot diverge.
 *
 * It is idempotent: calling it twice for the same payment is a no-op, which is
 * what makes duplicate webhooks and provider retries safe.
 */
class SettlePayment
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @param  int|null  $verifiedAmountMinor  What the provider says it collected.
     * @param  string|null  $verifiedCurrency  The currency the provider settled in.
     */
    public function execute(
        Payment $payment,
        ?User $actor = null,
        ?int $verifiedAmountMinor = null,
        ?string $verifiedCurrency = null,
        ?string $providerTransactionId = null,
        string $source = 'webhook',
    ): Payment {
        return DB::transaction(function () use (
            $payment,
            $actor,
            $verifiedAmountMinor,
            $verifiedCurrency,
            $providerTransactionId,
            $source,
        ): Payment {
            $locked = Payment::query()
                ->whereKey($payment->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            // Already settled: return unchanged. This single check is what makes
            // a duplicate webhook, a provider retry, and a customer refreshing
            // the return URL all harmless.
            if ($locked->status->isSettled()) {
                return $locked;
            }

            if (! $locked->canTransitionTo(PaymentStatus::Paid)) {
                throw ValidationException::withMessages([
                    'status' => "A {$locked->status->label()} payment cannot be settled.",
                ]);
            }

            $this->assertAmountMatches($locked, $verifiedAmountMinor, $verifiedCurrency);

            $now = now();

            $locked->forceFill([
                'status' => PaymentStatus::Paid,
                'paid_at' => $now,
                'provider_transaction_id' => $providerTransactionId ?? $locked->provider_transaction_id,
                'failure_reason' => null,
            ])->save();

            $allocated = $this->allocate($locked, $actor);

            $this->auditLogger->record(
                event: 'payment.settled',
                auditable: $locked,
                oldValues: ['status' => $payment->status->value],
                newValues: [
                    'status' => PaymentStatus::Paid->value,
                    'paid_at' => $now->toIso8601String(),
                    'amount_minor' => $locked->amount_minor,
                    'currency' => $locked->currency,
                    'allocated' => $allocated,
                    'source' => $source,
                ],
                user: $actor,
            );

            return $locked->fresh(['payable', 'allocations', 'customer']);
        }, 3);
    }

    /**
     * The provider's reported amount must match the intent exactly.
     *
     * Without this, a tampered callback or a misrouted notification could settle
     * a large booking against a trivial payment.
     */
    private function assertAmountMatches(Payment $payment, ?int $amountMinor, ?string $currency): void
    {
        if ($amountMinor === null && $currency === null) {
            // Nothing to compare — used by manual settlement, where an operator
            // is the authority and the audit entry records who.
            return;
        }

        if ($currency !== null && strtoupper($currency) !== $payment->currency) {
            throw ValidationException::withMessages([
                'currency' => 'The provider settled in a different currency than was requested.',
            ]);
        }

        if ($amountMinor !== null && $amountMinor !== $payment->amount_minor) {
            throw ValidationException::withMessages([
                'amount' => 'The settled amount does not match the requested amount.',
            ]);
        }
    }

    /**
     * Credit the payable and let it update its own state.
     *
     * firstOrCreate against the unique (payment, target) key means a retry
     * cannot credit the same booking twice even if this runs concurrently.
     */
    private function allocate(Payment $payment, ?User $actor): bool
    {
        $payable = $payment->payable;

        if (! $payable instanceof Model || ! $payable instanceof Payable) {
            return false;
        }

        $locked = $payable->newQuery()
            ->whereKey($payable->getKey())
            ->lockForUpdate()
            ->first();

        if (! $locked instanceof Payable) {
            return false;
        }

        PaymentAllocation::query()->firstOrCreate(
            [
                'payment_id' => $payment->getKey(),
                'allocatable_type' => $locked->getMorphClass(),
                'allocatable_id' => $locked->getKey(),
            ],
            [
                'amount_minor' => $payment->amount_minor,
                'currency' => $payment->currency,
                'allocated_by_user_id' => $actor?->getKey(),
            ],
        );

        // The payable updates its own payment status and may record a durable
        // loyalty-eligibility event. Implementations must be safe to call twice.
        $locked->applySettledPayment($payment);

        return true;
    }
}
