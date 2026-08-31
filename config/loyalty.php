<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Earning
    |--------------------------------------------------------------------------
    |
    | One point per this many minor units of the reporting base currency. At
    | the UGX default (exponent 0) that is one point per 10,000 UGX spent.
    |
    | Points are computed from the base-currency amount stamped on the payment,
    | so a later exchange-rate change cannot retroactively alter what a past
    | booking earned.
    |
    */
    'minor_units_per_point' => max(1, (int) env('PISFA_LOYALTY_MINOR_UNITS_PER_POINT', 10_000)),

    /*
    |--------------------------------------------------------------------------
    | Redemption
    |--------------------------------------------------------------------------
    |
    | Fixed by the product brief: a 500-point minimum, each point worth
    | UGX 100. The value is expressed in minor units of the base currency.
    |
    */
    'redemption' => [
        'minimum_points' => max(1, (int) env('PISFA_LOYALTY_MINIMUM_REDEMPTION', 500)),
        'minor_units_per_point' => max(1, (int) env('PISFA_LOYALTY_POINT_VALUE_MINOR', 100)),
    ],

    /*
    |--------------------------------------------------------------------------
    | Referrals
    |--------------------------------------------------------------------------
    |
    | The joining customer is rewarded on sign-up. The referrer is rewarded only
    | after the referred customer completes a first eligible booking, so a
    | referral cannot be farmed with throwaway accounts.
    |
    */
    'referral' => [
        'join_points' => max(0, (int) env('PISFA_LOYALTY_REFERRAL_JOIN_POINTS', 100)),
        'reward_points' => max(0, (int) env('PISFA_LOYALTY_REFERRAL_REWARD_POINTS', 200)),
    ],

    /*
    |--------------------------------------------------------------------------
    | Expiry
    |--------------------------------------------------------------------------
    |
    | A balance expires after this many months without activity. A warning is
    | sent ahead of time so the expiry is never a surprise.
    |
    */
    'expiry' => [
        'inactivity_months' => max(1, (int) env('PISFA_LOYALTY_EXPIRY_MONTHS', 12)),
        'warning_days_before' => max(1, (int) env('PISFA_LOYALTY_EXPIRY_WARNING_DAYS', 30)),
    ],

    'mail' => [
        'max_per_minute' => max(1, min(1000, (int) env('PISFA_LOYALTY_MAIL_MAX_PER_MINUTE', 8))),
    ],
];
