<?php

namespace App\Contracts\Payments;

use App\Enums\PaymentProvider;
use App\Models\Payment;
use App\Models\Refund;
use App\Support\Payments\InitiationResult;
use App\Support\Payments\RefundResult;
use App\Support\Payments\VerificationResult;
use App\Support\Payments\WebhookEvent;
use Illuminate\Http\Request;

/**
 * The seam every payment provider plugs into.
 *
 * Implementations are responsible for translating a provider's vocabulary into
 * the shared PaymentStatus/RefundStatus enums and normalised result objects.
 * They must never write business state themselves — the calling action does
 * that inside a transaction, after verifying amount, currency, and ownership.
 *
 * Automated tests bind a fake implementation, so no test ever needs real
 * credentials or network access.
 */
interface PaymentGateway
{
    public function provider(): PaymentProvider;

    /**
     * Whether this gateway is usable right now: enabled in config and holding
     * every credential it needs. An unconfigured provider must be invisible at
     * checkout rather than offered and then failing at the gateway.
     */
    public function isAvailable(): bool;

    /**
     * Ask the provider to begin collecting. Must not mutate the payment.
     *
     * @param  array<string, mixed>  $context  Payer details a provider requires (phone, return URLs).
     */
    public function initiate(Payment $payment, array $context = []): InitiationResult;

    /**
     * Ask the provider for the authoritative current state of a payment.
     *
     * Used by the status page, by reconciliation, and to confirm a webhook
     * whose signature verified but whose contents should still be corroborated.
     */
    public function verify(Payment $payment): VerificationResult;

    /**
     * Verify the request's signature and, where the provider supports it, its
     * timestamp against the configured replay tolerance.
     *
     * Returning false must cause the caller to reject the delivery before any
     * parsing or business logic runs.
     */
    public function verifyWebhookSignature(Request $request): bool;

    /**
     * Translate a verified request into a normalised event.
     *
     * Returning null means the payload was understood but is not relevant; the
     * caller acknowledges it without changing state.
     */
    public function parseWebhook(Request $request): ?WebhookEvent;

    /**
     * Request a full or partial refund. The refund row already exists and is
     * the caller's idempotency anchor.
     */
    public function refund(Payment $payment, Refund $refund): RefundResult;
}
