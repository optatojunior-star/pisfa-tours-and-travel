<?php

return [
    'maximum_units' => max(1, min(100, (int) env('PISFA_IMPORT_MAX_UNITS', 20))),

    'quote_validity_days' => max(1, min(90, (int) env('PISFA_IMPORT_QUOTE_VALIDITY_DAYS', 14))),

    /*
    |--------------------------------------------------------------------------
    | Minimum budget, in minor units
    |--------------------------------------------------------------------------
    |
    | Below this an import is not commercially viable once shipping, duty, and
    | clearance are counted, so the form refuses it rather than wasting the
    | customer's time and a consultant's.
    |
    */
    'minimum_budget' => [
        'UGX' => max(0, (int) env('PISFA_IMPORT_MIN_BUDGET_UGX', 5_000_000)),
        'USD' => max(0, (int) env('PISFA_IMPORT_MIN_BUDGET_USD', 150_000)),
    ],

    'purposes' => [
        'personal' => 'Personal use',
        'family' => 'Family vehicle',
        'business' => 'Business or commercial use',
        'fleet' => 'Fleet expansion',
        'resale' => 'Resale',
    ],

    'origin_countries' => [
        'JP' => 'Japan',
        'GB' => 'United Kingdom',
        'SG' => 'Singapore',
        'AE' => 'United Arab Emirates',
        'ZA' => 'South Africa',
        'DE' => 'Germany',
        'US' => 'United States',
        'TH' => 'Thailand',
    ],

    'mail' => [
        'max_per_minute' => max(1, min(1000, (int) env('PISFA_IMPORT_MAIL_MAX_PER_MINUTE', 8))),
    ],
];
