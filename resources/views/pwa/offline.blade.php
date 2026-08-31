<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#064e3b">
    <title>You are offline | PISFA Tours and Travels</title>
    {{--
        Styles are inline rather than linked. This page is precached and shown
        precisely when the network is gone, so a stylesheet it had to fetch
        would leave it unstyled at the one moment it is ever seen.
    --}}
    <style>
        :root { color-scheme: light; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 1.5rem;
            background: #f5f5f4;
            color: #0f172a;
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            line-height: 1.6;
        }
        main {
            max-width: 32rem;
            width: 100%;
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 1rem;
            padding: 2rem;
            text-align: center;
        }
        .mark {
            display: inline-grid;
            place-items: center;
            width: 3.5rem;
            height: 3.5rem;
            border-radius: 9999px;
            background: #ecfdf5;
            color: #047857;
            margin-bottom: 1rem;
        }
        h1 { margin: 0 0 .5rem; font-size: 1.5rem; color: #064e3b; }
        p { margin: 0 0 1rem; color: #475569; }
        ul { text-align: left; color: #475569; padding-left: 1.25rem; margin: 0 0 1.5rem; }
        li { margin-bottom: .35rem; }
        button {
            min-height: 2.75rem;
            padding: .625rem 1.5rem;
            border: 0;
            border-radius: .75rem;
            background: #047857;
            color: #fff;
            font-weight: 700;
            font-size: .95rem;
            cursor: pointer;
        }
        button:hover { background: #065f46; }
        button:focus-visible { outline: 3px solid #047857; outline-offset: 2px; }
        .tel { display: block; margin-top: 1.25rem; font-size: .875rem; color: #475569; }
        .tel a { color: #047857; }
        @media (prefers-reduced-motion: no-preference) {
            .mark { transition: transform .2s ease; }
        }
    </style>
</head>
<body>
    <main>
        <span class="mark" aria-hidden="true">
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M1 1l22 22M16.7 16.7A9 9 0 0 1 12 18M5 12.6a7 7 0 0 1 3-1.4M2 8.8a12 12 0 0 1 4-2.5M22 8.8a12 12 0 0 0-9.8-2.7M12 21h.01" />
            </svg>
        </span>

        <h1>You are offline</h1>

        <p>
            This page needs a connection. Nothing you had entered has been sent,
            so nothing has been booked or paid.
        </p>

        <ul>
            <li>Check your mobile data or Wi-Fi.</li>
            <li>If you were part-way through a booking, start it again once you reconnect — it was not submitted.</li>
            <li>Pages you have already loaded in this session may still work.</li>
        </ul>

        <button type="button" onclick="window.location.reload()">Try again</button>

        <span class="tel">
            Urgent? Call us on
            <a href="tel:+256700000000">+256 700 000 000</a>.
        </span>
    </main>
</body>
</html>
