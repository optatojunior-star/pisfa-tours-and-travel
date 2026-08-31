<?php

namespace App\Providers;

use App\Enums\PaymentProvider;
use App\Services\Payments\Gateways\ManualGateway;
use App\Services\Payments\PaymentGatewayRegistry;
use Illuminate\Support\ServiceProvider;

class PaymentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One registry per request. Adapters are registered here so call sites
        // resolve rather than construct, and tests can swap in a fake.
        $this->app->singleton(PaymentGatewayRegistry::class, function (): PaymentGatewayRegistry {
            $registry = new PaymentGatewayRegistry;

            foreach ([PaymentProvider::BankTransfer, PaymentProvider::Cash] as $provider) {
                $registry->register(new ManualGateway($provider));
            }

            // Remote adapters (MTN, Airtel, Stripe, PayPal, PesaPal,
            // Flutterwave) register here as each is implemented. Until then a
            // provider enabled in config fails loudly at resolution rather than
            // silently accepting money it cannot collect.

            return $registry;
        });
    }
}
