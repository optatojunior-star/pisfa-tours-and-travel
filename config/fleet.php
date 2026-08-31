<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Alerts
    |--------------------------------------------------------------------------
    |
    | How far ahead to warn about expiring vehicle paperwork. Insurance and
    | registration are the two that stop a vehicle legally working, so the
    | default gives a month to renew them.
    |
    */
    'alerts' => [
        'document_notice_days' => max(1, (int) env('PISFA_FLEET_DOCUMENT_NOTICE_DAYS', 30)),

        /*
         * How long before the same warning is repeated. Expired insurance
         * genuinely deserves nagging, but not an identical email every
         * morning — that is how an alert stops being read.
         */
        'repeat_after_days' => max(1, (int) env('PISFA_FLEET_ALERT_REPEAT_DAYS', 7)),
    ],

    /*
    |--------------------------------------------------------------------------
    | Reporting
    |--------------------------------------------------------------------------
    |
    | The default window for cost and utilisation figures on the fleet console.
    |
    */
    'reporting' => [
        'cost_window_months' => max(1, (int) env('PISFA_FLEET_COST_WINDOW_MONTHS', 12)),
        'utilisation_window_days' => max(1, (int) env('PISFA_FLEET_UTILISATION_DAYS', 30)),
    ],

    'mail' => [
        'max_per_minute' => max(1, min(1000, (int) env('PISFA_FLEET_MAIL_MAX_PER_MINUTE', 8))),
    ],
];
