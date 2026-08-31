<?php

namespace App\Http\Controllers\Payments;

use App\Actions\Payments\CreatePaymentIntent;
use App\Contracts\Payments\Payable;
use App\Enums\PaymentProvider;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payments\StartCheckoutRequest;
use App\Models\Payment;
use App\Services\Payments\PaymentGatewayRegistry;
use App\Support\Payments\PayableRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class CheckoutController extends Controller
{
    public function __construct(private readonly PaymentGatewayRegistry $registry) {}

    public function show(Request $request, string $type, string $reference): View
    {
        $payable = $this->resolvePayable($request, $type, $reference);

        abort_unless($payable->acceptsPayment(), 404);

        $outstanding = $payable->outstandingAmountMinor();
        abort_if($outstanding < 1, 404);

        $providers = $this->registry->availableForCustomer($payable->payableCurrency());
        $idempotencyKey = (string) Str::uuid();

        return view('payments.checkout', compact(
            'payable',
            'type',
            'outstanding',
            'providers',
            'idempotencyKey',
        ));
    }

    public function store(
        StartCheckoutRequest $request,
        string $type,
        string $reference,
        CreatePaymentIntent $action,
    ): RedirectResponse {
        $payable = $this->resolvePayable($request, $type, $reference);
        $provider = PaymentProvider::from($request->validated('provider'));

        $payment = $action->execute(
            $request->user(),
            $payable,
            $provider,
            $request->validated('idempotency_key'),
            ['return_url' => $payable->paymentReturnUrl()],
        );

        return redirect()->route('payments.status', $payment);
    }

    public function status(Request $request, Payment $payment): View
    {
        $this->authorize('view', $payment);

        $gateway = $this->registry->for($payment->provider);
        $instructions = [];

        // A manual provider re-renders its instructions on every visit, since
        // the customer needs them until they have actually paid.
        if ($payment->provider->isManual() && $payment->status->isInFlight()) {
            $instructions = $gateway->initiate($payment)->instructions;
        }

        return view('payments.status', [
            'payment' => $payment->load('payable'),
            'instructions' => $instructions,
        ]);
    }

    /** @return Model&Payable */
    private function resolvePayable(Request $request, string $type, string $reference): Payable
    {
        $class = PayableRegistry::classFor($type);
        abort_if($class === null, 404);

        $user = $request->user();
        abort_unless($user !== null, 403);

        $payable = $class::query()
            ->where('reference', $reference)
            // Scoped to the signed-in customer, so a foreign reference is a 404
            // rather than a 403 that would confirm it exists.
            ->when(
                ! $user->canAccessAdministration(),
                fn (Builder $query): Builder => $query->where('customer_id', $user->getKey()),
            )
            ->first();

        // The allowlist guarantees the class, but asserting it here keeps the
        // contract explicit rather than implied by a docblock.
        abort_unless($payable instanceof Model && $payable instanceof Payable, 404);

        return $payable;
    }
}
