<?php

namespace App\Actions\Payments;

use App\Enums\AccountStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Enums\UserRole;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Payments\PaymentGatewayRegistry;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Issues a full or partial refund against a settled payment.
 *
 * Refunds are a finance action: manager and super administrator only, never
 * staff. Every refund is idempotency-keyed, and refundable headroom is
 * recomputed under a row lock so two concurrent requests cannot together exceed
 * what was collected.
 */
class RefundPayment
{
    public function __construct(
        private readonly PaymentGatewayRegistry $registry,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function execute(
        User $actor,
        Payment $payment,
        int $amountMinor,
        string $reason,
        string $idempotencyKey,
    ): Refund {
        $reason = trim($reason);

        Validator::make(
            [
                'amount_minor' => $amountMinor,
                'reason' => $reason,
                'idempotency_key' => trim($idempotencyKey),
            ],
            [
                'amount_minor' => ['required', 'integer', 'min:1'],
                'reason' => ['required', 'string', 'min:5', 'max:1000'],
                'idempotency_key' => ['required', 'uuid'],
            ],
        )->validate();

        $this->assertFinanceActor($actor);

        [$refund, $shouldDispatch] = DB::transaction(function () use (
            $actor,
            $payment,
            $amountMinor,
            $reason,
            $idempotencyKey,
        ): array {
            $lockedActor = User::query()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();
            $this->assertFinanceActor($lockedActor);

            $locked = Payment::query()
                ->whereKey($payment->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $existing = Refund::query()
                ->where('payment_id', $locked->getKey())
                ->where('idempotency_key', trim($idempotencyKey))
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return [$existing, false];
            }

            if (! $locked->status->isRefundable()) {
                throw ValidationException::withMessages([
                    'payment' => "A {$locked->status->label()} payment cannot be refunded.",
                ]);
            }

            // Headroom is recomputed here, inside the lock, so two concurrent
            // partial refunds cannot together exceed the collected amount.
            $available = $locked->refundableAmountMinor();

            if ($amountMinor > $available) {
                throw ValidationException::withMessages([
                    'amount_minor' => 'The refund exceeds the refundable balance of '
                        .Money::format($available, $locked->currency).'.',
                ]);
            }

            $refund = new Refund;
            $refund->forceFill([
                'reference' => 'REF-'.Str::upper((string) Str::ulid()),
                'payment_id' => $locked->getKey(),
                'requested_by_user_id' => $lockedActor->getKey(),
                'status' => RefundStatus::Pending,
                'amount_minor' => $amountMinor,
                'currency' => $locked->currency,
                'reason' => $reason,
                'idempotency_key' => trim($idempotencyKey),
            ])->save();

            $this->auditLogger->record(
                event: 'payment.refund_requested',
                auditable: $refund,
                newValues: [
                    'payment_reference' => $locked->reference,
                    'amount_minor' => $amountMinor,
                    'currency' => $locked->currency,
                    'refundable_before' => $available,
                ],
                user: $lockedActor,
            );

            return [$refund, true];
        }, 3);

        if (! $shouldDispatch) {
            return $refund;
        }

        return $this->dispatchToProvider($actor, $payment, $refund);
    }

    /**
     * Ask the gateway to move the money, then reconcile the ledger.
     *
     * The provider call happens outside the first transaction so a slow gateway
     * cannot hold locks; the result is applied in a second short transaction.
     */
    private function dispatchToProvider(User $actor, Payment $payment, Refund $refund): Refund
    {
        $gateway = $this->registry->for($payment->provider);
        $result = $gateway->refund($payment, $refund);

        return DB::transaction(function () use ($actor, $refund, $result): Refund {
            $lockedRefund = Refund::query()->whereKey($refund->getKey())->lockForUpdate()->firstOrFail();
            $lockedPayment = Payment::query()
                ->whereKey($lockedRefund->payment_id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $lockedRefund->canTransitionTo($result->status)) {
                return $lockedRefund;
            }

            $lockedRefund->forceFill([
                'status' => $result->status,
                'provider_refund_id' => $result->providerRefundId,
                'failure_reason' => $result->failureReason,
                'metadata' => $result->metadata === [] ? null : $result->metadata,
                'completed_at' => $result->status === RefundStatus::Completed ? now() : null,
            ])->save();

            if ($result->status === RefundStatus::Completed) {
                $this->applyCompletedRefund($lockedPayment, $lockedRefund);
            }

            $this->auditLogger->record(
                event: 'payment.refund_'.$result->status->value,
                auditable: $lockedRefund,
                newValues: [
                    'status' => $result->status->value,
                    'amount_minor' => $lockedRefund->amount_minor,
                    'payment_status' => $lockedPayment->fresh()?->status->value,
                ],
                user: $actor,
            );

            return $lockedRefund;
        }, 3);
    }

    /**
     * Move the parent payment to partially or fully refunded.
     *
     * The running total lives on the payment row so refundable headroom is one
     * locked read rather than an aggregate that could be computed stale.
     */
    private function applyCompletedRefund(Payment $payment, Refund $refund): void
    {
        $refunded = (int) Refund::query()
            ->where('payment_id', $payment->getKey())
            ->completed()
            ->sum('amount_minor');

        $status = $refunded >= $payment->amount_minor
            ? PaymentStatus::Refunded
            : PaymentStatus::PartiallyRefunded;

        if ($payment->status !== $status && ! $payment->canTransitionTo($status)) {
            return;
        }

        $payment->forceFill([
            'refunded_amount_minor' => $refunded,
            'status' => $status,
        ])->save();
    }

    private function assertFinanceActor(User $actor): void
    {
        if ($actor->status !== AccountStatus::Active
            || ! $actor->hasAnyRole(UserRole::Manager, UserRole::SuperAdmin)) {
            throw new AuthorizationException;
        }
    }
}
