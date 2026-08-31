<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Tax
    |--------------------------------------------------------------------------
    |
    | Basis points, so 18% Uganda VAT is 1800 and the rate is never a float.
    | The rate in force is stamped onto each quotation and invoice, so a later
    | rate change cannot restate a document that has already been issued.
    |
    */
    'tax' => [
        'default_rate_bps' => max(0, min(10_000, (int) env('PISFA_TAX_RATE_BPS', 1800))),
        'label' => env('PISFA_TAX_LABEL', 'VAT'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Document numbering
    |--------------------------------------------------------------------------
    |
    | Prefixes for the gapless per-year series. Changing a prefix starts a new
    | series rather than renumbering the old one.
    |
    */
    'numbering' => [
        'quotation_prefix' => env('PISFA_QUOTATION_PREFIX', 'QTN'),
        'invoice_prefix' => env('PISFA_INVOICE_PREFIX', 'INV'),
        'pad' => max(3, min(10, (int) env('PISFA_DOCUMENT_NUMBER_PAD', 5))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Quotations
    |--------------------------------------------------------------------------
    |
    | How long an offer stands by default, and the ceiling on a single document
    | so a mis-typed quantity cannot produce an absurd total.
    |
    */
    'quotations' => [
        'default_validity_days' => max(1, (int) env('PISFA_QUOTATION_VALIDITY_DAYS', 14)),
        'maximum_items' => max(1, min(200, (int) env('PISFA_QUOTATION_MAX_ITEMS', 40))),
        'maximum_quantity' => max(1, (int) env('PISFA_QUOTATION_MAX_QUANTITY', 10_000)),
    ],

    /*
    |--------------------------------------------------------------------------
    | Invoices
    |--------------------------------------------------------------------------
    */
    'invoices' => [
        'default_payment_terms_days' => max(0, (int) env('PISFA_INVOICE_TERMS_DAYS', 14)),
    ],

    'mail' => [
        'max_per_minute' => max(1, min(1000, (int) env('PISFA_BILLING_MAIL_MAX_PER_MINUTE', 8))),
    ],
];
