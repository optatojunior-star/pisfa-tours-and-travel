<?php

namespace App\Services\Payments\Gateways;

use App\Contracts\Payments\PaymentGateway;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\Refund;
use App\Support\Payments\InitiationResult;
use App\Support\Payments\RefundResult;
use App\Support\Payments\VerificationResult;
use App\Support\Payments\WebhookEvent;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Bank transfer and cash.
 *
 * There is no API. The customer is shown instructions and pays out of band; an
 * administrator later records receipt against evidence. That recording happens
 * through SettlePayment with an explicit actor, never here — this gateway
 * cannot settle anything on its own, which is the point.
 */
class ManualGateway implements PaymentGateway
{
    public function __construct(private readonly PaymentProvider $provider)
    {
        if (! $provider->isManual()) {
            throw new RuntimeException('ManualGateway only serves manual providers.');
        }
    }

    public function provider(): PaymentProvider
    {
        return $this->provider;
    }

    public function isAvailable(): bool
    {
        return $this->provider->isEnabled();
    }

    public function initiate(Payment $payment, array $context = []): InitiationResult
    {
        if ($this->provider === PaymentProvider::Cash) {
            // Cash is recorded by staff against money already in hand. A
            // customer-facing checkout never reaches this branch.
            return InitiationResult::manualInstructions([
                'headline' => 'Cash payment',
                'detail' => 'Pay at a PISFA office. A member of staff will record the receipt.',
            ]);
        }

        $config = (array) config($this->provider->configKey(), []);

        return InitiationResult::manualInstructions([
            'headline' => 'Bank transfer instructions',
            'detail' => 'Transfer the exact amount and quote your payment reference. '
                .'We confirm receipt manually, which can take one to two working days.',
            'reference' => $payment->reference,
            'amount' => $payment->formattedAmount(),
            'bank_name' => (string) ($config['bank_name'] ?? ''),
            'account_name' => (string) ($config['account_name'] ?? ''),
            'account_number' => (string) ($config['account_number'] ?? ''),
            'branch' => (string) ($config['branch'] ?? ''),
            'swift' => (string) ($config['swift'] ?? ''),
        ]);
    }

    /**
     * There is no upstream to ask, so the stored status is authoritative.
     */
    public function verify(Payment $payment): VerificationResult
    {
        return new VerificationResult(
            status: $payment->status,
            amountMinor: $payment->amount_minor,
            currency: $payment->currency,
            providerTransactionId: $payment->provider_transaction_id,
        );
    }

    public function verifyWebhookSignature(Request $request): bool
    {
        // A manual provider never sends webhooks, so any request claiming to be
        // one is illegitimate by definition.
        return false;
    }

    public function parseWebhook(Request $request): ?WebhookEvent
    {
        return null;
    }

    public function refund(Payment $payment, Refund $refund): RefundResult
    {
        // The money moves by hand. Marking it Processing keeps it claimed
        // against refundable headroom until an operator confirms it left.
        return RefundResult::processing(metadata: [
            'requires_manual_disbursement' => true,
            'provider' => $this->provider->value,
        ]);
    }

    /**
     * The status a manual intent starts in, so callers do not have to special
     * case it: bank transfer waits for confirmation, cash is already in hand.
     */
    public function initialStatus(): PaymentStatus
    {
        return PaymentStatus::Pending;
    }
}
