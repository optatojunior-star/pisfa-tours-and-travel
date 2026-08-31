<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Terms
    |--------------------------------------------------------------------------
    |
    | The default notice period offered to owners, in days, and the ceiling on
    | a revenue share. The ceiling is a guard against a typo: a share is stored
    | in basis points, so 5000 is half the revenue and 50000 would be five times
    | it. Nothing above 100% can be a real agreement.
    |
    */
    'default_notice_period_days' => max(0, min(365, (int) env('PISFA_LEASE_NOTICE_DAYS', 30))),

    'max_revenue_share_bps' => 10000,

    /*
    |--------------------------------------------------------------------------
    | Payouts
    |--------------------------------------------------------------------------
    |
    | Which day of the following month statements are drawn up on. Left to the
    | office rather than fired at midnight, because somebody has to look at the
    | figures before an owner is told what they are getting.
    |
    */
    'payout_run_day' => max(1, min(28, (int) env('PISFA_LEASE_PAYOUT_DAY', 3))),

    'mail' => [
        'max_per_minute' => max(1, min(1000, (int) env('PISFA_LEASING_MAIL_MAX_PER_MINUTE', 8))),
    ],
];
