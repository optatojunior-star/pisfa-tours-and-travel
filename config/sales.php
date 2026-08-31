<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Showroom
    |--------------------------------------------------------------------------
    |
    | How many listings a page shows, and how long a sold vehicle stays on the
    | public showroom. Recent sales read as a going concern; a page full of
    | year-old ones reads as a business with no stock.
    |
    */
    'showroom' => [
        'per_page' => max(3, min(48, (int) env('PISFA_SHOWROOM_PER_PAGE', 12))),
        'sold_visible_days' => max(0, (int) env('PISFA_SHOWROOM_SOLD_DAYS', 30)),
    ],

    'mail' => [
        'max_per_minute' => max(1, min(1000, (int) env('PISFA_SALES_MAIL_MAX_PER_MINUTE', 8))),
    ],
];
