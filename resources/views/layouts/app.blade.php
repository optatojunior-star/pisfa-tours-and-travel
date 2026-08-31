<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="theme-color" content="#04574e">

        <title>{{ isset($title) ? $title.' | ' : '' }}PISFA Operations</title>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />

        {{-- Livewire 3 supplies Alpine for the shell interactions below. --}}
        @vite(['resources/css/app.css'])
        @livewireStyles
        @stack('styles')
        <x-pwa-head />

        <style>[x-cloak] { display: none !important; }</style>
    </head>
    <body class="bg-slate-50 font-sans text-slate-900 antialiased">
        <a href="#main-content" class="sr-only z-[100] rounded-md bg-emerald-700 px-4 py-2 text-sm font-semibold text-white focus:not-sr-only focus:fixed focus:left-4 focus:top-4">
            Skip to main content
        </a>

        <div class="flex min-h-screen flex-col">
            @include('layouts.navigation')

            @isset($header)
                <header class="border-b border-slate-200 bg-white" aria-label="Page heading">
                    <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
                        {{ $header }}
                    </div>
                </header>
            @endisset

            <main id="main-content" class="flex-1">
                {{ $slot }}
            </main>

            <footer class="border-t border-slate-200 bg-white">
                <div class="mx-auto flex max-w-7xl flex-col gap-2 px-4 py-5 text-xs text-slate-500 sm:flex-row sm:items-center sm:justify-between sm:px-6 lg:px-8">
                    <p>&copy; {{ now()->year }} PISFA Tour &amp; Travel. Built for dependable journeys.</p>
                    <p class="inline-flex items-center gap-2">
                        <span class="h-2 w-2 rounded-full bg-emerald-500" aria-hidden="true"></span>
                        Secure operations workspace
                    </p>
                </div>
            </footer>
        </div>

        @livewireScripts
        @stack('scripts')
    </body>
</html>
