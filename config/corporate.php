<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Terms
    |--------------------------------------------------------------------------
    |
    | Defaults offered to a new account. The discount ceiling is a guard against
    | a typo: a discount is stored in basis points, so 500 is 5% and 5000 would
    | be half the price. Nothing at or above 100% can be a real discount.
    |
    */
    'default_payment_terms_days' => max(0, min(180, (int) env('PISFA_CORPORATE_TERMS_DAYS', 30))),

    'max_discount_bps' => 9900,

    /*
    |--------------------------------------------------------------------------
    | Groups
    |--------------------------------------------------------------------------
    |
    | The smallest party PISFA treats as a group, and the largest a single
    | booking may carry. One traveller is an ordinary booking; five hundred is
    | a programme that needs breaking into several.
    |
    */
    'minimum_group_size' => max(2, (int) env('PISFA_GROUP_MIN', 2)),

    'maximum_group_size' => max(2, min(2000, (int) env('PISFA_GROUP_MAX', 500))),

    'mail' => [
        'max_per_minute' => max(1, min(1000, (int) env('PISFA_CORPORATE_MAIL_MAX_PER_MINUTE', 8))),
    ],
];
