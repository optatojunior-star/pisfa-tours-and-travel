<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Review requests
    |--------------------------------------------------------------------------
    |
    | How long after a booking completes we ask the customer to review it, and
    | how far back the sweep will still reach. The cutoff stops a long outage,
    | or a first deployment against existing data, from mailing every customer
    | who ever travelled with PISFA.
    |
    | One request per booking, ever: the marker row carries a unique constraint,
    | so a repeated sweep cannot mail the same customer twice.
    |
    */
    'requests' => [
        'delay_hours' => max(1, (int) env('PISFA_REVIEW_REQUEST_DELAY_HOURS', 24)),
        'max_age_days' => max(1, (int) env('PISFA_REVIEW_REQUEST_MAX_AGE_DAYS', 30)),
        'batch_size' => max(1, min(500, (int) env('PISFA_REVIEW_REQUEST_BATCH_SIZE', 100))),
    ],

    'mail' => [
        'max_per_minute' => max(1, min(1000, (int) env('PISFA_REVIEW_MAIL_MAX_PER_MINUTE', 8))),
    ],
];
