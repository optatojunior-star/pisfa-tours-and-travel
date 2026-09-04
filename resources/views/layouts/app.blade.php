<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="theme-color" content="#04574e">

        <title>{{ isset($title) ? $title.' | ' : '' }}PISFA Operations</title>

        {{--
            No webfont link here. Inter is self-hosted and imported by app.css.
            This layout used to pull Figtree from a CDN — a font the config had
            already stopped naming — so the console rendered in a different
            typeface from the public site, and every page paid for a third-party
            DNS lookup and round trip to get it.
        --}}
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @livewireStyles
        @stack('styles')
        <x-pwa-head />

        <style>[x-cloak] { display: none !important; }</style>
    </head>
    <body class="bg-ink-50 font-sans text-ink-900 antialiased">
        <a href="#main-content"
           class="sr-only z-[100] rounded-control bg-brand-800 px-4 py-2 text-sm font-semibold text-white focus:not-sr-only focus:fixed focus:left-4 focus:top-4">
            Skip to main content
        </a>

        {{--
            Sidebar and content sit side by side on large screens. The sidebar is
            its own scroll region, so a long menu never pushes the page down and
            the content column keeps the full viewport height.
        --}}
        <div class="lg:flex">
            <x-console-sidebar />

            <div class="flex min-h-screen min-w-0 flex-1 flex-col">
                @isset($header)
                    <header class="border-b border-ink-200 bg-white" aria-label="Page heading">
                        <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
                            {{ $header }}
                        </div>
                    </header>
                @endisset

                <main id="main-content" class="flex-1">
                    {{ $slot }}
                </main>

                <footer class="border-t border-ink-200 bg-white">
                    <div class="mx-auto flex max-w-7xl flex-col gap-2 px-4 py-5 text-xs text-ink-500 sm:flex-row sm:items-center sm:justify-between sm:px-6 lg:px-8">
                        <p>&copy; {{ now()->year }} PISFA Tour &amp; Travel. Built for dependable journeys.</p>
                        <p class="inline-flex items-center gap-2">
                            <span class="h-2 w-2 rounded-full bg-brand-500" aria-hidden="true"></span>
                            Secure operations workspace
                        </p>
                    </div>
                </footer>
            </div>
        </div>

        @livewireScripts
        @stack('scripts')
    </body>
</html>
