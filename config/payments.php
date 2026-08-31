<?php

use App\Enums\PaymentProvider;

return [
    /*
    |--------------------------------------------------------------------------
    | Reporting base currency
    |--------------------------------------------------------------------------
    |
    | Every payment stores its amount in the currency the customer pays, plus
    | the equivalent in this base currency at the rate in force when the intent
    | was created. Reports aggregate the base amount, so a later rate change
    | cannot rewrite historic revenue.
    |
    */
    'base_currency' => strtoupper((string) env('PISFA_BASE_CURRENCY', 'UGX')),

    /*
    |--------------------------------------------------------------------------
    | Exchange rates (parts per million)
    |--------------------------------------------------------------------------
    |
    | Integer parts-per-million of the target per one unit of the source.
    | 3,800 UGX per USD is written as 3_800_000_000.
    |
    | Integers, not floats: a float rate multiplied into a minor-unit amount
    | reintroduces the rounding error the money design exists to avoid.
    |
    */
    'exchange_rates' => [
        'USD_UGX' => max(1, (int) env('PISFA_RATE_USD_UGX', 3_800_000_000)),
    ],

    /*
    |--------------------------------------------------------------------------
    | Intent lifetime
    |--------------------------------------------------------------------------
    |
    | A payment intent that receives no authoritative result inside this window
    | is expired by the scheduler, releasing the customer to try again.
    |
    */
    'intent_expiry_minutes' => max(5, min(1440, (int) env('PISFA_PAYMENT_EXPIRY_MINUTES', 60))),

    /*
    |--------------------------------------------------------------------------
    | Webhook handling
    |--------------------------------------------------------------------------
    */
    'webhooks' => [
        // Reject a signed payload whose timestamp is outside this window, so a
        // captured request cannot be replayed later. Providers that do not sign
        // a timestamp are handled by event-id deduplication alone.
        'tolerance_seconds' => max(60, min(3600, (int) env('PISFA_WEBHOOK_TOLERANCE_SECONDS', 300))),

        // Retain raw (redacted) webhook payloads for this long for dispute
        // resolution, then prune.
        'retention_days' => max(7, min(3650, (int) env('PISFA_WEBHOOK_RETENTION_DAYS', 180))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Providers
    |--------------------------------------------------------------------------
    |
    | A provider appears at checkout only when enabled AND its credentials are
    | present. Nothing is enabled by default: an unconfigured provider must be
    | invisible rather than offered and then failing at the gateway.
    |
    | Manual providers (bank transfer, cash) need no credentials. Bank transfer
    | is the customer-selectable manual option; cash is staff-recorded only.
    |
    */
    'providers' => [
        PaymentProvider::BankTransfer->value => [
            'enabled' => (bool) env('PISFA_PAY_BANK_TRANSFER_ENABLED', true),
            'account_name' => env('PISFA_BANK_ACCOUNT_NAME', ''),
            'account_number' => env('PISFA_BANK_ACCOUNT_NUMBER', ''),
            'bank_name' => env('PISFA_BANK_NAME', ''),
            'branch' => env('PISFA_BANK_BRANCH', ''),
            'swift' => env('PISFA_BANK_SWIFT', ''),
        ],

        PaymentProvider::Cash->value => [
            'enabled' => (bool) env('PISFA_PAY_CASH_ENABLED', true),
        ],

        PaymentProvider::MtnMobileMoney->value => [
            'enabled' => (bool) env('PISFA_PAY_MTN_ENABLED', false),
            'environment' => env('MTN_MOMO_ENVIRONMENT', 'sandbox'),
            'subscription_key' => env('MTN_MOMO_SUBSCRIPTION_KEY'),
            'api_user' => env('MTN_MOMO_API_USER'),
            'api_key' => env('MTN_MOMO_API_KEY'),
        ],

        PaymentProvider::AirtelMoney->value => [
            'enabled' => (bool) env('PISFA_PAY_AIRTEL_ENABLED', false),
            'environment' => env('AIRTEL_MONEY_ENVIRONMENT', 'sandbox'),
            'client_id' => env('AIRTEL_MONEY_CLIENT_ID'),
            'client_secret' => env('AIRTEL_MONEY_CLIENT_SECRET'),
        ],

        PaymentProvider::Stripe->value => [
            'enabled' => (bool) env('PISFA_PAY_STRIPE_ENABLED', false),
            'key' => env('STRIPE_KEY'),
            'secret' => env('STRIPE_SECRET'),
            'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        ],

        PaymentProvider::PayPal->value => [
            'enabled' => (bool) env('PISFA_PAY_PAYPAL_ENABLED', false),
            'mode' => env('PAYPAL_MODE', 'sandbox'),
            'client_id' => env('PAYPAL_CLIENT_ID'),
            'client_secret' => env('PAYPAL_CLIENT_SECRET'),
            'webhook_id' => env('PAYPAL_WEBHOOK_ID'),
        ],

        PaymentProvider::PesaPal->value => [
            'enabled' => (bool) env('PISFA_PAY_PESAPAL_ENABLED', false),
            'environment' => env('PESAPAL_ENVIRONMENT', 'sandbox'),
            'consumer_key' => env('PESAPAL_CONSUMER_KEY'),
            'consumer_secret' => env('PESAPAL_CONSUMER_SECRET'),
        ],

        PaymentProvider::Flutterwave->value => [
            'enabled' => (bool) env('PISFA_PAY_FLUTTERWAVE_ENABLED', false),
            'public_key' => env('FLUTTERWAVE_PUBLIC_KEY'),
            'secret_key' => env('FLUTTERWAVE_SECRET_KEY'),
            'encryption_key' => env('FLUTTERWAVE_ENCRYPTION_KEY'),
            'webhook_secret' => env('FLUTTERWAVE_WEBHOOK_SECRET'),
        ],
    ],
];
