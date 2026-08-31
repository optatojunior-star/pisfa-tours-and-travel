<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Holds
    |--------------------------------------------------------------------------
    |
    | How long a pending stay keeps its rooms while the desk reviews it. The
    | hold is what stops two guests being sold the same last room between the
    | request and the confirmation; it is capped at the arrival moment, because
    | a hold that outlives the check-in it is holding is meaningless.
    |
    */
    'pending_hold_minutes' => max(15, min(20160, (int) env('PISFA_STAY_HOLD_MINUTES', 1440))),

    /*
    |--------------------------------------------------------------------------
    | Stay limits
    |--------------------------------------------------------------------------
    |
    | Long stays are a negotiated rate rather than a nightly one, so the booking
    | form stops at this length and sends the guest to a quotation instead of
    | quietly multiplying a nightly price by ninety.
    |
    */
    'max_nights' => max(1, min(365, (int) env('PISFA_STAY_MAX_NIGHTS', 60))),

    /*
    |--------------------------------------------------------------------------
    | Catalogue
    |--------------------------------------------------------------------------
    */
    'catalogue' => [
        'per_page' => max(3, min(48, (int) env('PISFA_ACCOMMODATION_PER_PAGE', 12))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Arrival reminders
    |--------------------------------------------------------------------------
    |
    | How many days before arrival a confirmed guest is reminded. The event
    | marker makes a repeated sweep harmless.
    |
    */
    'arrival_reminder_days' => max(1, min(30, (int) env('PISFA_STAY_REMINDER_DAYS', 2))),

    'mail' => [
        'max_per_minute' => max(1, min(1000, (int) env('PISFA_ACCOMMODATION_MAIL_MAX_PER_MINUTE', 8))),
    ],
];
