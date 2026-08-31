<?php

namespace App\Actions\Payments;

use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\PaymentWebhookEvent;
use App\Services\AuditLogger;
use App\Services\Payments\PaymentGatewayRegistry;
use App\Support\Payments\WebhookEvent;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Handles an inbound provider notification.
 *
 * Order matters and is deliberate:
 *
 *   1. Verify the signature. An unverified payload is logged and rejected
 *      before it is parsed, so malformed hostile input never reaches a parser.
 *   2. Record the event under a unique (provider, event_id) key. A replay
 *      collides at the database, not at an application check that could race.
 *   3. Only then resolve the payment and change state.
 */
class ProcessPaymentWebhook
{
    public function __construct(
        private readonly PaymentGatewayRegistry $registry,
        private readonly SettlePayment $settlePayment,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @return array{handled: bool, duplicate: bool, reason: ?string}
     */
    public function execute(PaymentProvider $provider, Request $request): array
    {
        $gateway = $this->registry->for($provider);

        if (! $gateway->verifyWebhookSignature($request)) {
            $this->auditLogger->record(
                event: 'payment.webhook_rejected',
                newValues: [
                    'provider' => $provider->value,
                    'reason' => 'signature_verification_failed',
                ],
            );

            return ['handled' => false, 'duplicate' => false, 'reason' => 'invalid_signature'];
        }

        $event = $gateway->parseWebhook($request);

        if ($event === null) {
            // Understood but irrelevant. Acknowledge so the provider stops
            // retrying, but change nothing.
            return ['handled' => false, 'duplicate' => false, 'reason' => 'ignored'];
        }

        $payload = $this->redact($event->payload);

        try {
            $record = PaymentWebhookEvent::query()->create([
                'provider' => $provider,
                'event_id' => $event->eventId,
                'event_type' => $event->eventType,
                'signature_verified' => true,
                'payload_sha256' => hash('sha256', json_encode($payload) ?: ''),
                'payload' => $payload,
                'received_at' => now(),
            ]);
        } catch (QueryException) {
            // Unique (provider, event_id) violated: this exact delivery has
            // already been recorded. Acknowledging without reprocessing is the
            // whole duplicate-webhook defence.
            return ['handled' => false, 'duplicate' => true, 'reason' => 'duplicate_event'];
        }

        try {
            $handled = $this->apply($event, $record);

            $record->forceFill(['processed_at' => now()])->save();

            return ['handled' => $handled, 'duplicate' => false, 'reason' => null];
        } catch (Throwable $exception) {
            // Keep the row with its error so the delivery is visible and can be
            // retried by an operator, rather than vanishing into a 500.
            $record->forceFill([
                'processing_error' => mb_substr($exception->getMessage(), 0, 1000),
            ])->save();

            report($exception);

            return ['handled' => false, 'duplicate' => false, 'reason' => 'processing_failed'];
        }
    }

    private function apply(WebhookEvent $event, PaymentWebhookEvent $record): bool
    {
        if (! $event->isActionable()) {
            return false;
        }

        $payment = $this->resolvePayment($event);

        if ($payment === null) {
            return false;
        }

        $record->forceFill(['payment_id' => $payment->getKey()])->save();

        // The provider must match. A Stripe event must never settle a payment
        // that was created against Flutterwave.
        if ($payment->provider !== $event->provider) {
            throw new \RuntimeException('Webhook provider does not match the payment provider.');
        }

        return match (true) {
            $event->status === PaymentStatus::Paid => $this->settle($payment, $event),
            $event->status === PaymentStatus::Failed => $this->fail($payment, $event, PaymentStatus::Failed),
            $event->status === PaymentStatus::Cancelled => $this->fail($payment, $event, PaymentStatus::Cancelled),
            $event->status === PaymentStatus::Processing,
            $event->status === PaymentStatus::RequiresAction => $this->advance($payment, $event),
            default => false,
        };
    }

    private function resolvePayment(WebhookEvent $event): ?Payment
    {
        if ($event->paymentReference !== null) {
            $payment = Payment::query()->where('reference', $event->paymentReference)->first();

            if ($payment !== null) {
                return $payment;
            }
        }

        if ($event->providerTransactionId !== null) {
            return Payment::query()
                ->where('provider', $event->provider->value)
                ->where('provider_transaction_id', $event->providerTransactionId)
                ->first();
        }

        return null;
    }

    private function settle(Payment $payment, WebhookEvent $event): bool
    {
        $this->settlePayment->execute(
            payment: $payment,
            actor: null,
            verifiedAmountMinor: $event->amountMinor,
            verifiedCurrency: $event->currency,
            providerTransactionId: $event->providerTransactionId,
            source: 'webhook',
        );

        return true;
    }

    private function fail(
        Payment $payment,
        WebhookEvent $event,
        PaymentStatus $status,
    ): bool {
        return (bool) DB::transaction(function () use ($payment, $event, $status): bool {
            $locked = Payment::query()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();

            // A settled payment is never downgraded by a late failure notice.
            if ($locked->status->isSettled() || ! $locked->canTransitionTo($status)) {
                return false;
            }

            $locked->forceFill([
                'status' => $status,
                'failure_reason' => $event->failureReason,
                $status === PaymentStatus::Cancelled ? 'cancelled_at' : 'failed_at' => now(),
            ])->save();

            $this->auditLogger->record(
                event: 'payment.'.$status->value,
                auditable: $locked,
                oldValues: ['status' => $payment->status->value],
                newValues: ['status' => $status->value, 'source' => 'webhook'],
            );

            return true;
        }, 3);
    }

    private function advance(Payment $payment, WebhookEvent $event): bool
    {
        return (bool) DB::transaction(function () use ($payment, $event): bool {
            $locked = Payment::query()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();

            if ($event->status === null || ! $locked->canTransitionTo($event->status)) {
                return false;
            }

            $locked->forceFill([
                'status' => $event->status,
                'provider_transaction_id' => $event->providerTransactionId ?? $locked->provider_transaction_id,
                'initiated_at' => $locked->initiated_at ?? now(),
            ])->save();

            return true;
        }, 3);
    }

    /**
     * Provider payloads routinely carry card fragments, tokens, and phone
     * numbers. Nothing sensitive is persisted, even in the raw event log.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function redact(array $payload): array
    {
        $sensitive = [
            'card', 'card_number', 'pan', 'last4', 'cvv', 'cvc', 'expiry',
            'token', 'secret', 'signature', 'authorization', 'api_key',
            'phone', 'msisdn', 'email', 'account_number',
        ];

        $walker = function (array $data) use (&$walker, $sensitive): array {
            foreach ($data as $key => $value) {
                if (is_string($key) && in_array(mb_strtolower($key), $sensitive, true)) {
                    $data[$key] = '[REDACTED]';

                    continue;
                }

                if (is_array($value)) {
                    $data[$key] = $walker($value);
                }
            }

            return $data;
        };

        return $walker(Arr::except($payload, ['raw', 'signature']));
    }
}
