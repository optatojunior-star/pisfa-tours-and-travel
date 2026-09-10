<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Storage disks
    |--------------------------------------------------------------------------
    |
    | "private" holds identity documents, contracts, receipts, payroll files and
    | import paperwork. It must never be reachable over HTTP; it is served only
    | through an authorized download controller.
    |
    | "public" holds catalogue media (vehicle, property, tour, blog images) that
    | is genuinely public.
    |
    | Hostinger Premium uses the local driver for both. Cloudinary or an
    | S3-compatible bucket can be substituted per environment without touching
    | application code, because every write goes through DocumentStorage.
    |
    */
    'disks' => [
        'private' => env('PISFA_PRIVATE_DISK', 'local'),
        'public' => env('PISFA_PUBLIC_DISK', 'public'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Accepted content
    |--------------------------------------------------------------------------
    |
    | Keyed by the MIME type detected from the file's actual bytes, never from
    | the browser-supplied Content-Type or the filename. The extension list is
    | the set the detected type is allowed to arrive with; the first entry is
    | the canonical extension written to storage.
    |
    */
    'accepted' => [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/webp' => ['webp'],
        'application/pdf' => ['pdf'],
    ],

    'images' => [
        'mimes' => ['image/jpeg', 'image/png', 'image/webp'],
        'maximum_kilobytes' => max(64, min(20480, (int) env('PISFA_IMAGE_MAX_KB', 5120))),
        'minimum_width' => 200,
        'minimum_height' => 200,
        'maximum_width' => 10000,
        'maximum_height' => 10000,

        /*
        |----------------------------------------------------------------------
        | Shrinking in the browser
        |----------------------------------------------------------------------
        |
        | A phone photograph is 3-6 MB at around 4000x3000. Twelve of them is
        | sixty megabytes of request body, which on a domestic Ugandan upstream
        | takes minutes — long enough for the proxy in front of PHP to give up
        | and return a 504 Gateway Timeout with nothing saved.
        |
        | The upload component redraws anything larger than the threshold onto a
        | canvas no bigger than the longest edge before sending it. 1920px is
        | wider than any place the site displays a photograph, so nothing
        | visible is lost, and the same twelve pictures come to about 4 MB.
        |
        | Raising the longest edge raises upload time roughly with its square.
        | Setting it to 0 is not supported; disable per form with :shrink="false".
        |
        */
        'browser_longest_edge' => max(600, min(4000, (int) env('PISFA_IMAGE_BROWSER_EDGE', 1920))),
        'browser_shrink_over_kilobytes' => max(100, (int) env('PISFA_IMAGE_BROWSER_SHRINK_OVER_KB', 900)),
    ],

    'files' => [
        'maximum_kilobytes' => max(64, min(51200, (int) env('PISFA_FILE_MAX_KB', 10240))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Signed download links
    |--------------------------------------------------------------------------
    |
    | Used where a private file must be reachable without a session, such as a
    | guest quotation. Authenticated customers and staff use the authorized
    | download route instead, which has no expiry to manage.
    |
    */
    'signed_url_minutes' => max(1, min(1440, (int) env('PISFA_SIGNED_URL_MINUTES', 15))),

    /*
    |--------------------------------------------------------------------------
    | Generated PDFs
    |--------------------------------------------------------------------------
    */
    'pdf' => [
        'paper' => env('PISFA_PDF_PAPER', 'a4'),
        'orientation' => 'portrait',
    ],
];
