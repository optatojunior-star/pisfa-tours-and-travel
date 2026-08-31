<?php

namespace App\Enums;

enum PaymentProvider: string
{
    case MtnMobileMoney = 'mtn_momo';
    case AirtelMoney = 'airtel_money';
    case Stripe = 'stripe';
    case PayPal = 'paypal';
    case PesaPal = 'pesapal';
    case Flutterwave = 'flutterwave';
    case BankTransfer = 'bank_transfer';
    case Cash = 'cash';

    public function label(): string
    {
        return match ($this) {
            self::MtnMobileMoney => 'MTN Mobile Money',
            self::AirtelMoney => 'Airtel Money',
            self::Stripe => 'Card (Stripe)',
            self::PayPal => 'PayPal',
            self::PesaPal => 'PesaPal',
            self::Flutterwave => 'Flutterwave',
            self::BankTransfer => 'Bank transfer',
            self::Cash => 'Cash',
        };
    }

    /**
     * Manual providers have no API. Settlement is recorded by an administrator
     * against evidence, so they never produce a webhook and never auto-refund.
     */
    public function isManual(): bool
    {
        return in_array($this, [self::BankTransfer, self::Cash], true);
    }

    /**
     * Cash is recorded by staff only. A customer may select bank transfer at
     * checkout and receive instructions, but can never mark it paid.
     */
    public function isCustomerSelectable(): bool
    {
        return $this !== self::Cash;
    }

    public function sendsWebhooks(): bool
    {
        return ! $this->isManual();
    }

    public function supportsAutomatedRefunds(): bool
    {
        return ! $this->isManual();
    }

    /**
     * Currencies a provider can actually settle. Mobile money in Uganda is UGX
     * only; offering USD would produce a guaranteed failure at the provider.
     *
     * @return list<string>
     */
    public function supportedCurrencies(): array
    {
        return match ($this) {
            self::MtnMobileMoney, self::AirtelMoney => ['UGX'],
            self::Stripe, self::PayPal => ['USD'],
            self::PesaPal, self::Flutterwave, self::BankTransfer, self::Cash => ['UGX', 'USD'],
        };
    }

    public function supportsCurrency(string $currency): bool
    {
        return in_array(strtoupper(trim($currency)), $this->supportedCurrencies(), true);
    }

    /**
     * The config key holding this provider's credentials and enabled flag.
     */
    public function configKey(): string
    {
        return 'payments.providers.'.$this->value;
    }

    public function isEnabled(): bool
    {
        return (bool) config($this->configKey().'.enabled', false);
    }

    /** @return list<self> */
    public static function enabledCases(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $provider): bool => $provider->isEnabled(),
        ));
    }
}
