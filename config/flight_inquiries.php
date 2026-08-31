<?php

return [
    'maximum_passengers' => max(
        1,
        min(500, (int) env('PISFA_FLIGHT_INQUIRY_MAXIMUM_PASSENGERS', 50)),
    ),

    'minimum_notice_days' => max(
        0,
        min(365, (int) env('PISFA_FLIGHT_INQUIRY_MINIMUM_NOTICE_DAYS', 1)),
    ),

    'maximum_advance_days' => max(
        1,
        min(3650, (int) env('PISFA_FLIGHT_INQUIRY_MAXIMUM_ADVANCE_DAYS', 365)),
    ),

    'maximum_trip_length_days' => max(
        1,
        min(365, (int) env('PISFA_FLIGHT_INQUIRY_MAXIMUM_TRIP_LENGTH_DAYS', 180)),
    ),

    /*
    |--------------------------------------------------------------------------
    | Reopening resolved inquiries
    |--------------------------------------------------------------------------
    |
    | A closed or cancelled inquiry can return to the follow-up queue, but only
    | by a manager or super administrator and only a bounded number of times,
    | so the queue cannot be recycled indefinitely to hide an ageing request.
    |
    */
    'reopen' => [
        'roles' => ['manager', 'super_admin'],
        'maximum_per_inquiry' => max(
            1,
            min(20, (int) env('PISFA_FLIGHT_INQUIRY_MAXIMUM_REOPENS', 3)),
        ),
    ],

    'guest_tracking' => [
        'expiry_hours' => max(
            1,
            min(2160, (int) env('PISFA_FLIGHT_INQUIRY_GUEST_TRACKING_EXPIRY_HOURS', 168)),
        ),
    ],

    'mail' => [
        'max_per_minute' => max(
            1,
            min(1000, (int) env('PISFA_FLIGHT_INQUIRY_MAIL_MAX_PER_MINUTE', 8)),
        ),
    ],
];
