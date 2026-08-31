<?php

namespace App\Http\Controllers\Messaging;

use App\Actions\Messaging\HandleInboundWebhook;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as ResponseCode;

/**
 * The WhatsApp provider's callback.
 *
 * Two rules govern the responses here. A bad signature is a 4xx, so a
 * misconfigured integration is loud rather than silently swallowed. Everything
 * else is a 200, because a provider that receives an error retries — and a
 * payload this deployment cannot use will fail identically on every retry,
 * turning one unusable message into an indefinite stream of them.
 */
class WhatsAppWebhookController extends Controller
{
    /**
     * Meta's subscription handshake.
     *
     * Compared in constant time against the configured token. A plain `===`
     * here leaks the token a byte at a time to anybody willing to send enough
     * requests.
     */
    public function verify(Request $request): Response
    {
        $expected = (string) config('messaging.whatsapp.verify_token', '');
        $presented = (string) $request->query('hub_verify_token', '');
        $challenge = (string) $request->query('hub_challenge', '');

        if ($expected === '' || ! hash_equals($expected, $presented)) {
            return response('Forbidden.', ResponseCode::HTTP_FORBIDDEN);
        }

        return response($challenge, ResponseCode::HTTP_OK)
            ->header('Content-Type', 'text/plain');
    }

    public function __invoke(Request $request, HandleInboundWebhook $action): Response
    {
        $raw = $request->getContent();

        /** @var array<string, string> $headers */
        $headers = [];

        foreach ($request->headers->all() as $name => $values) {
            $headers[$name] = (string) ($values[0] ?? '');
        }

        $payload = $request->json()->all();

        $result = $action->execute($raw, is_array($payload) ? $payload : [], $headers);

        if ($result['reason'] === 'invalid_signature') {
            return response('Invalid signature.', ResponseCode::HTTP_BAD_REQUEST);
        }

        // The reason is echoed for the provider's own delivery log. It names an
        // outcome and never quotes the payload back.
        return response($result['reason'], ResponseCode::HTTP_OK)
            ->header('Content-Type', 'text/plain');
    }
}
