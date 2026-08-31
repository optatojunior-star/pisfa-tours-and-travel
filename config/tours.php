<?php

$currencies = array_values(array_intersect(
    config('pisfa.currency.supported', ['UGX', 'USD']),
    ['UGX', 'USD'],
));

return [
    'currencies' => $currencies,

    'maximum_duration_days' => 90,

    'maximum_departure_capacity' => 500,

    /*
    |--------------------------------------------------------------------------
    | Booking and cancellation windows
    |--------------------------------------------------------------------------
    |
    | A scheduled departure remains bookable only while its persisted
    | cancellation_cutoff_at is in the future. Keeping the cutoff on the
    | departure makes the rule visible, auditable, and stable for every booking.
    |
    */
    'default_cancellation_cutoff_hours' => max(
        1,
        (int) env('PISFA_TOUR_CANCELLATION_CUTOFF_HOURS', 48),
    ),

    'maximum_booking_travelers' => max(
        1,
        min(500, (int) env('PISFA_TOUR_MAXIMUM_BOOKING_TRAVELERS', 50)),
    ),

    'mail' => [
        'max_per_minute' => max(
            1,
            min(1000, (int) env('PISFA_TOUR_MAIL_MAX_PER_MINUTE', 8)),
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Departure reminders
    |--------------------------------------------------------------------------
    |
    | Run `tours:send-departure-reminders` at least as often as the window.
    | A booking is selected when its departure falls in the half-open interval
    | [now + lead, now + lead + window). A unique booking event prevents a
    | second reminder when the scheduler overlaps or is invoked twice.
    |
    */
    'reminders' => [
        'lead_minutes' => max(
            1,
            (int) env('PISFA_TOUR_REMINDER_LEAD_MINUTES', 1440),
        ),
        'window_minutes' => max(
            1,
            (int) env('PISFA_TOUR_REMINDER_WINDOW_MINUTES', 15),
        ),
    ],

];
