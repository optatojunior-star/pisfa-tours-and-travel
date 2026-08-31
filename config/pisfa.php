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
        'tagline' => env('PISFA_COMPANY_TAGLINE', 'Uganda travel, transport and fleet services'),
        'email' => env('PISFA_CONTACT_EMAIL', 'info@example.com'),
        'phone' => env('PISFA_CONTACT_PHONE', '+256700000000'),
        'address' => env('PISFA_COMPANY_ADDRESS', 'Kampala, Uganda'),
        'registration_number' => env('PISFA_COMPANY_REGISTRATION', ''),
        'tax_identification_number' => env('PISFA_COMPANY_TIN', ''),
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
