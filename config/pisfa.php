<?php

$supportedCurrencies = array_values(array_filter(array_map(
    static fn (string $currency): string => strtoupper(trim($currency)),
    explode(',', (string) env('PISFA_SUPPORTED_CURRENCIES', 'UGX,USD')),
)));

return [
    'business_timezone' => env('APP_BUSINESS_TIMEZONE', 'Africa/Kampala'),

    'currency' => [
        'default' => strtoupper((string) env('PISFA_DEFAULT_CURRENCY', 'UGX')),
        'supported' => $supportedCurrencies,
    ],

    'contact' => [
        'email' => env('PISFA_CONTACT_EMAIL', 'info@example.com'),
        'phone' => env('PISFA_CONTACT_PHONE', '+256700000000'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Company identity
    |--------------------------------------------------------------------------
    |
    | Printed on every generated PDF (contracts, quotations, invoices, payslips,
    | receipts, statements). Environment-driven so a rename, address change, or
    | new TIN does not require a code change. F26 will surface these in the
    | settings UI and persist overrides in the settings table.
    |
    */
    'company' => [
        'name' => env('PISFA_COMPANY_NAME', 'PISFA Tours and Travels'),

        /*
         * The registered name, which is not the trading name.
         *
         * The certificate of incorporation says PISFA TOUR AND TRAVEL
         * LIMITED; customers know the business as PISFA Tours and Travels.
         * Both are correct in their place, and the difference matters: a
         * limited company's invoices, contracts and terms have to carry the
         * registered name and number, while its marketing does not.
         */
        'legal_name' => env('PISFA_COMPANY_LEGAL_NAME', 'PISFA Tour and Travel Limited'),
        'tagline' => env('PISFA_COMPANY_TAGLINE', 'Uganda travel, transport and fleet services'),
        'email' => env('PISFA_CONTACT_EMAIL', 'info@example.com'),
        'phone' => env('PISFA_CONTACT_PHONE', '+256700000000'),
        'address' => env('PISFA_COMPANY_ADDRESS', 'Kitebi Starlink Building, Kitebi, Bunamwaya, Rubaga Division, Kampala, Uganda'),
        // Public information, printed on every invoice and contract — this
        // is an identifier from a public register, not a credential.
        'registration_number' => env('PISFA_COMPANY_REGISTRATION', '80034149987265'),
        'incorporated_on' => env('PISFA_COMPANY_INCORPORATED_ON', '2026-04-10'),
        'tax_identification_number' => env('PISFA_COMPANY_TIN', ''),

        /*
         * The WhatsApp number customers message, which is not always the office
         * line — a floating button that opens a chat to a landline helps nobody.
         * Digits only, with the country code and no plus: that is the shape
         * wa.me expects, and the accessor below enforces it whatever is typed.
         */
        'whatsapp' => env('PISFA_WHATSAPP_NUMBER', '256758375435'),
    ],

    'staff' => [
        'invitation_expiry_hours' => max(
            1,
            (int) env('PISFA_STAFF_INVITATION_EXPIRY_HOURS', 72),
        ),
    ],

    'hosting' => [
        'database_queue' => true,
        'polling_fallback' => true,
        'persistent_process_required' => false,
    ],
];
