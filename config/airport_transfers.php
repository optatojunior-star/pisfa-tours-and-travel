<?php

$currencies = array_values(array_intersect(
    config('pisfa.currency.supported', ['UGX', 'USD']),
    ['UGX', 'USD'],
));

$minimumRateDurationMinutes = max(
    1,
    min(1440, (int) env('PISFA_AIRPORT_TRANSFER_RATE_MINIMUM_DURATION_MINUTES', 15)),
);

$maximumRateDurationMinutes = max(
    $minimumRateDurationMinutes,
    min(10080, (int) env('PISFA_AIRPORT_TRANSFER_RATE_MAXIMUM_DURATION_MINUTES', 1440)),
);

return [
    'currencies' => $currencies,

    'maximum_passengers' => max(
        1,
        min(500, (int) env('PISFA_AIRPORT_TRANSFER_MAXIMUM_PASSENGERS', 50)),
    ),

    'maximum_luggage' => max(
        0,
        min(1000, (int) env('PISFA_AIRPORT_TRANSFER_MAXIMUM_LUGGAGE', 100)),
    ),

    'minimum_notice_hours' => max(
        0,
        min(168, (int) env('PISFA_AIRPORT_TRANSFER_MINIMUM_NOTICE_HOURS', 2)),
    ),

    'maximum_advance_days' => max(
        1,
        min(3650, (int) env('PISFA_AIRPORT_TRANSFER_MAXIMUM_ADVANCE_DAYS', 365)),
    ),

    'request_expiry_minutes' => max(
        15,
        min(10080, (int) env('PISFA_AIRPORT_TRANSFER_REQUEST_EXPIRY_MINUTES', 1440)),
    ),

    'default_cancellation_cutoff_hours' => max(
        1,
        min(720, (int) env('PISFA_AIRPORT_TRANSFER_CANCELLATION_CUTOFF_HOURS', 4)),
    ),

    'rate_duration' => [
        'minimum_minutes' => $minimumRateDurationMinutes,
        'maximum_minutes' => $maximumRateDurationMinutes,
    ],

    'guest_confirmation' => [
        'expiry_hours' => max(
            1,
            min(2160, (int) env('PISFA_AIRPORT_TRANSFER_GUEST_CONFIRMATION_EXPIRY_HOURS', 168)),
        ),
    ],

    'mail' => [
        'max_per_minute' => max(
            1,
            min(1000, (int) env('PISFA_AIRPORT_TRANSFER_MAIL_MAX_PER_MINUTE', 8)),
        ),
    ],

    'pickup_reminders' => [
        'lead_minutes' => max(
            1,
            min(525600, (int) env('PISFA_AIRPORT_TRANSFER_PICKUP_REMINDER_LEAD_MINUTES', 1440)),
        ),
        'window_minutes' => max(
            1,
            min(1440, (int) env('PISFA_AIRPORT_TRANSFER_PICKUP_REMINDER_WINDOW_MINUTES', 15)),
        ),
    ],
];
