<?php

$currencies = array_values(array_intersect(
    config('pisfa.currency.supported', ['UGX', 'USD']),
    ['UGX', 'USD'],
));

return [
    'currencies' => $currencies,
    'maximum_hire_days' => max(1, min(365, (int) env('PISFA_CAR_HIRE_MAXIMUM_DAYS', 90))),
    'minimum_notice_hours' => max(0, min(168, (int) env('PISFA_CAR_HIRE_MINIMUM_NOTICE_HOURS', 2))),
    'default_cancellation_cutoff_hours' => max(
        1,
        min(720, (int) env('PISFA_CAR_HIRE_CANCELLATION_CUTOFF_HOURS', 48)),
    ),
    'pending_hold_minutes' => max(15, min(10080, (int) env('PISFA_CAR_HIRE_PENDING_HOLD_MINUTES', 1440))),
    'documents' => [
        'disk' => env('PISFA_PRIVATE_DOCUMENT_DISK', 'local'),
        'maximum_kilobytes' => max(512, min(10240, (int) env('PISFA_CAR_HIRE_DOCUMENT_MAX_KB', 5120))),
    ],
    'contract' => [
        'version' => env('PISFA_CAR_HIRE_CONTRACT_VERSION', '2026-08-20'),
    ],
    'mail' => [
        'max_per_minute' => max(1, min(1000, (int) env('PISFA_CAR_HIRE_MAIL_MAX_PER_MINUTE', 8))),
    ],
    'return_reminders' => [
        'lead_minutes' => max(1, (int) env('PISFA_CAR_HIRE_RETURN_REMINDER_LEAD_MINUTES', 1440)),
        'window_minutes' => max(1, (int) env('PISFA_CAR_HIRE_RETURN_REMINDER_WINDOW_MINUTES', 15)),
    ],
];
