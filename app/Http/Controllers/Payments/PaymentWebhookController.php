<?php

namespace App\Http\Controllers\Payments;

use App\Actions\Payments\ProcessPaymentWebhook;
use App\Enums\PaymentProvider;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Inbound provider notifications.
 *
 * This route is necessarily CSRF-exempt and unauthenticated, so its integrity
 * rests entirely on signature verification, event-id deduplication, and the
 * amount/currency/ownership checks in the settlement action.
 */
class PaymentWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        string $provider,
        ProcessPaymentWebhook $action,
    ): JsonResponse {
        $paymentProvider = PaymentProvider::tryFrom($provider);

        if ($paymentProvider === null || ! $paymentProvider->sendsWebhooks()) {
            return response()->json(['message' => 'Unknown provider.'], 404);
        }

        $result = $action->execute($paymentProvider, $request);

        // A bad signature is the one case worth a 4xx, so a misconfigured
        // provider surfaces loudly instead of silently succeeding forever.
        if ($result['reason'] === 'invalid_signature') {
            return response()->json(['message' => 'Invalid signature.'], 400);
        }

        // Everything else is acknowledged with 200 — including duplicates and
        // events we chose not to act on — so the provider stops retrying.
        // Processing failures are retained with their error for operator retry.
        return response()->json([
            'received' => true,
            'handled' => $result['handled'],
            'duplicate' => $result['duplicate'],
        ]);
    }
}
