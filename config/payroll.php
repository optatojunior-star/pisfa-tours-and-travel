<?php

return [
    /*
    |--------------------------------------------------------------------------
    | NSSF
    |--------------------------------------------------------------------------
    |
    | Uganda's National Social Security Fund: the employee contributes 5% of
    | gross and the employer adds 10% on top. Basis points, so the rate is an
    | integer and never a float — 500 is 5%.
    |
    | The employer share is not deducted from the employee. It is recorded
    | separately as employer cost, because a payslip that showed 15% coming off
    | somebody's salary would be wrong by a factor of three.
    |
    */
    'nssf' => [
        'enabled' => (bool) env('PISFA_PAYROLL_NSSF_ENABLED', true),
        'employee_bps' => max(0, min(10000, (int) env('PISFA_PAYROLL_NSSF_EMPLOYEE_BPS', 500))),
        'employer_bps' => max(0, min(10000, (int) env('PISFA_PAYROLL_NSSF_EMPLOYER_BPS', 1000))),
    ],

    /*
    |--------------------------------------------------------------------------
    | PAYE
    |--------------------------------------------------------------------------
    |
    | Uganda's monthly resident individual income tax bands, in UGX minor units
    | (UGX has no minor unit, so these are whole shillings).
    |
    | `up_to` is the top of the band; null means "and above". `rate_bps` applies
    | to the part of the chargeable income falling inside the band, and
    | `surcharge_bps` is the additional levy applied to the amount above
    | `surcharge_above` — the top band carries an extra charge that is not part
    | of the marginal rate.
    |
    | Bands change with the finance act, which is why they are configuration and
    | not constants in the calculator. Anything charging PAYE on a currency
    | these bands are not written in is refused rather than guessed at.
    |
    */
    'paye' => [
        'enabled' => (bool) env('PISFA_PAYROLL_PAYE_ENABLED', true),
        'currency' => env('PISFA_PAYROLL_PAYE_CURRENCY', 'UGX'),
        'bands' => [
            ['up_to' => 235_000, 'rate_bps' => 0],
            ['up_to' => 335_000, 'rate_bps' => 1000],
            ['up_to' => 410_000, 'rate_bps' => 2000],
            ['up_to' => 10_000_000, 'rate_bps' => 3000],
            ['up_to' => null, 'rate_bps' => 3000],
        ],
        // The additional 10% on chargeable income above 10,000,000 a month.
        'surcharge_above' => 10_000_000,
        'surcharge_bps' => 1000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Runs
    |--------------------------------------------------------------------------
    |
    | Which roles are on the payroll, and the currency a run defaults to. A
    | customer is never an employee, so the list is explicit rather than
    | "everybody who is not a customer".
    |
    */
    'employee_roles' => ['super_admin', 'manager', 'staff', 'driver'],

    'default_currency' => env('PISFA_PAYROLL_CURRENCY', 'UGX'),

    'mail' => [
        'max_per_minute' => max(1, min(1000, (int) env('PISFA_FINANCE_MAIL_MAX_PER_MINUTE', 8))),
    ],
];
