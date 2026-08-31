<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Live chat
    |--------------------------------------------------------------------------
    |
    | Hostinger Premium has no persistent Node process and no self-hosted
    | WebSocket server, so the chat widget polls rather than holding a socket
    | open. The interval below is the honest cost of that: a reply appears
    | within roughly one interval, not instantly.
    |
    */
    'chat' => [
        'poll_seconds' => max(3, min(60, (int) env('PISFA_CHAT_POLL_SECONDS', 8))),
        'page_size' => max(10, min(200, (int) env('PISFA_CHAT_PAGE_SIZE', 50))),
        'max_message_length' => 2000,
    ],

    /*
    |--------------------------------------------------------------------------
    | WhatsApp
    |--------------------------------------------------------------------------
    |
    | Credentials come from the environment and are never committed. When any of
    | them is missing the log transport is used instead, which records that a
    | message was not sent rather than pretending it was.
    |
    */
    'whatsapp' => [
        'token' => env('WHATSAPP_ACCESS_TOKEN'),
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        'app_secret' => env('WHATSAPP_APP_SECRET'),

        // Meta's webhook handshake echoes this back on subscription.
        'verify_token' => env('WHATSAPP_VERIFY_TOKEN'),

        'api_version' => env('WHATSAPP_API_VERSION', 'v21.0'),
        'timeout_seconds' => max(3, min(60, (int) env('WHATSAPP_TIMEOUT_SECONDS', 15))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Automatic replies
    |--------------------------------------------------------------------------
    |
    | A fixed, reviewable list — not generated text. An automatic reply is read
    | as PISFA speaking and, on WhatsApp, costs money per message.
    |
    | Business hours are indexed by Carbon's dayOfWeek, where 0 is Sunday. A
    | null entry means closed all day.
    |
    */
    'bot' => [
        'enabled' => (bool) env('PISFA_CHAT_BOT_ENABLED', true),
        'cooldown_minutes' => max(1, (int) env('PISFA_CHAT_BOT_COOLDOWN_MINUTES', 30)),

        'business_hours' => [
            0 => null,                  // Sunday
            1 => ['08:00', '18:00'],
            2 => ['08:00', '18:00'],
            3 => ['08:00', '18:00'],
            4 => ['08:00', '18:00'],
            5 => ['08:00', '18:00'],
            6 => ['09:00', '13:00'],    // Saturday
        ],

        'away_message' => 'Thank you for contacting PISFA Tours and Travel. Our office is closed at the '
            .'moment — we are open Monday to Friday, 8am to 6pm, and Saturday mornings. A member of the '
            .'team will reply as soon as we open.',

        'rules' => [
            [
                'name' => 'office-hours',
                'keywords' => ['opening hours', 'open hours', 'what time do you open', 'are you open'],
                'reply' => 'We are open Monday to Friday, 8am to 6pm, and Saturday from 9am to 1pm '
                    .'(East Africa Time). You can browse and book any time at our website.',
            ],
            [
                'name' => 'car-hire',
                'keywords' => ['car hire', 'rent a car', 'hire a car', 'self drive', 'self-drive'],
                'reply' => 'We hire out saloons, SUVs, vans and 4x4s, with or without a driver. '
                    .'Tell us the dates and where you are travelling and we will send you options and a price.',
            ],
            [
                'name' => 'airport-transfer',
                'keywords' => ['airport', 'entebbe', 'pick me', 'pickup from airport'],
                'reply' => 'We run airport transfers to and from Entebbe and the main upcountry airstrips. '
                    .'Send us your flight number, date and destination and we will confirm a price.',
            ],
            [
                'name' => 'tours',
                'keywords' => ['safari', 'tour', 'gorilla', 'murchison', 'bwindi', 'queen elizabeth'],
                'reply' => 'We run safaris across Uganda and the region — gorilla trekking, Murchison Falls, '
                    .'Queen Elizabeth and more. Tell us your dates and how many are travelling and we will '
                    .'put an itinerary together.',
            ],
            [
                'name' => 'payment',
                'keywords' => ['pay', 'payment', 'mobile money', 'deposit', 'invoice'],
                'reply' => 'You can pay by mobile money, card or bank transfer. If you already have a '
                    .'booking reference, send it and we will send you the payment link for it.',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate limits and logging
    |--------------------------------------------------------------------------
    */
    'mail' => [
        'max_per_minute' => max(1, min(1000, (int) env('PISFA_MESSAGING_MAIL_MAX_PER_MINUTE', 8))),
    ],

    'log_channel' => env('PISFA_MESSAGING_LOG_CHANNEL', 'stack'),
];
