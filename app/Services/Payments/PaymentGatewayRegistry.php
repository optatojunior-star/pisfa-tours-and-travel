<?php

namespace App\Services\Payments;

use App\Contracts\Payments\PaymentGateway;
use App\Enums\PaymentProvider;
use App\Services\Payments\Gateways\ManualGateway;
use RuntimeException;

/**
 * Resolves the gateway for a provider.
 *
 * Adapters register here rather than being newed up at call sites, so tests can
 * swap in a fake and unimplemented providers fail loudly at resolution instead
 * of silently doing nothing.
 */
class PaymentGatewayRegistry
{
    /** @var array<string, PaymentGateway> */
    private array $gateways = [];

    public function register(PaymentGateway $gateway): void
    {
        $this->gateways[$gateway->provider()->value] = $gateway;
    }

    public function for(PaymentProvider $provider): PaymentGateway
    {
        if (isset($this->gateways[$provider->value])) {
            return $this->gateways[$provider->value];
        }

        // Manual providers need no credentials, so they can always be built.
        if ($provider->isManual()) {
            return $this->gateways[$provider->value] = new ManualGateway($provider);
        }

        throw new RuntimeException(
            "No payment gateway is registered for {$provider->value}. ".
            'Register an adapter before enabling this provider.'
        );
    }

    public function has(PaymentProvider $provider): bool
    {
        return isset($this->gateways[$provider->value]) || $provider->isManual();
    }

    /**
     * Providers a customer may actually choose right now: enabled, registered,
     * available, currency-compatible, and customer-selectable.
     *
     * Filtering here rather than in a view is what prevents checkout offering
     * an option that is guaranteed to fail at the provider.
     *
     * @return list<PaymentProvider>
     */
    public function availableForCustomer(string $currency): array
    {
        return array_values(array_filter(
            PaymentProvider::enabledCases(),
            function (PaymentProvider $provider) use ($currency): bool {
                if (! $provider->isCustomerSelectable() || ! $provider->supportsCurrency($currency)) {
                    return false;
                }

                if (! $this->has($provider)) {
                    return false;
                }

                return $this->for($provider)->isAvailable();
            },
        ));
    }

    /**
     * Providers an administrator may record against, which additionally
     * includes cash.
     *
     * @return list<PaymentProvider>
     */
    public function availableForOperations(string $currency): array
    {
        return array_values(array_filter(
            PaymentProvider::enabledCases(),
            fn (PaymentProvider $provider): bool => $provider->supportsCurrency($currency)
                && $this->has($provider)
                && $this->for($provider)->isAvailable(),
        ));
    }
}
