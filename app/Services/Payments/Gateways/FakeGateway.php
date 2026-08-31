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

/**
 * Scriptable gateway for automated tests.
 *
 * Every provider path — success, pending, failure, cancellation, duplicate
 * callback, retry — is exercised through this, so no test needs real
 * credentials or network access, as the brief requires.
 *
 * It is bound only in the testing environment.
 */
class FakeGateway implements PaymentGateway
{
    private ?InitiationResult $nextInitiation = null;

    private ?VerificationResult $nextVerification = null;

    private ?RefundResult $nextRefund = null;

    private bool $signatureValid = true;

    private bool $available = true;

    /** @var list<array{payment: string, context: array<string, mixed>}> */
    public array $initiations = [];

    public function __construct(private readonly PaymentProvider $providerCase = PaymentProvider::Flutterwave) {}

    public function provider(): PaymentProvider
    {
        return $this->providerCase;
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function initiate(Payment $payment, array $context = []): InitiationResult
    {
        $this->initiations[] = ['payment' => $payment->reference, 'context' => $context];

        return $this->nextInitiation ?? InitiationResult::redirect(
            'https://sandbox.example.test/checkout/'.$payment->reference,
            providerReference: 'PROV-'.$payment->reference,
        );
    }

    public function verify(Payment $payment): VerificationResult
    {
        return $this->nextVerification ?? new VerificationResult(
            status: $payment->status,
            amountMinor: $payment->amount_minor,
            currency: $payment->currency,
        );
    }

    public function verifyWebhookSignature(Request $request): bool
    {
        return $this->signatureValid;
    }

    public function parseWebhook(Request $request): ?WebhookEvent
    {
        $data = (array) $request->json()->all();

        if (! isset($data['event_id'])) {
            return null;
        }

        $status = isset($data['status']) ? PaymentStatus::tryFrom((string) $data['status']) : null;

        return new WebhookEvent(
            provider: $this->providerCase,
            eventId: (string) $data['event_id'],
            eventType: isset($data['type']) ? (string) $data['type'] : null,
            paymentReference: isset($data['reference']) ? (string) $data['reference'] : null,
            providerTransactionId: isset($data['transaction_id']) ? (string) $data['transaction_id'] : null,
            status: $status,
            amountMinor: isset($data['amount_minor']) ? (int) $data['amount_minor'] : null,
            currency: isset($data['currency']) ? strtoupper((string) $data['currency']) : null,
            payload: $data,
            failureReason: isset($data['failure_reason']) ? (string) $data['failure_reason'] : null,
        );
    }

    public function refund(Payment $payment, Refund $refund): RefundResult
    {
        return $this->nextRefund ?? RefundResult::completed('RFND-'.$refund->reference);
    }

    // ---- Test scripting -------------------------------------------------

    public function willInitiate(InitiationResult $result): self
    {
        $this->nextInitiation = $result;

        return $this;
    }

    public function willVerify(VerificationResult $result): self
    {
        $this->nextVerification = $result;

        return $this;
    }

    public function willRefund(RefundResult $result): self
    {
        $this->nextRefund = $result;

        return $this;
    }

    public function withInvalidSignature(): self
    {
        $this->signatureValid = false;

        return $this;
    }

    public function unavailable(): self
    {
        $this->available = false;

        return $this;
    }
}
