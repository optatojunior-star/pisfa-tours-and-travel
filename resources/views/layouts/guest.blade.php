<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="theme-color" content="#04574e">

        <title>{{ isset($title) ? $title.' | ' : '' }}PISFA</title>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />

        {{-- Livewire 3 supplies Alpine without loading a second Alpine instance. --}}
        @vite(['resources/css/app.css'])
        @livewireStyles
        @stack('styles')
        <x-pwa-head />
    </head>
    <body class="bg-slate-50 font-sans text-slate-900 antialiased">
        <div class="grid min-h-screen lg:grid-cols-[minmax(0,0.92fr)_minmax(32rem,1.08fr)]">
            <aside class="relative hidden overflow-hidden bg-emerald-950 px-10 py-12 text-white lg:flex lg:flex-col lg:justify-between" aria-label="About PISFA">
                <div class="absolute -right-28 -top-24 h-80 w-80 rounded-full border border-emerald-700/50" aria-hidden="true"></div>
                <div class="absolute -right-8 top-16 h-52 w-52 rounded-full border border-emerald-600/40" aria-hidden="true"></div>
                <div class="absolute bottom-24 left-16 h-px w-72 rotate-[-24deg] bg-gradient-to-r from-transparent via-amber-300/70 to-transparent" aria-hidden="true"></div>

                <x-brand-logo :href="url('/')" size="lg" inverse class="relative w-fit" />

                <div class="relative max-w-lg">
                    <p class="mb-5 inline-flex items-center gap-2 rounded-full border border-emerald-700 bg-emerald-900/70 px-3 py-1 text-xs font-semibold uppercase tracking-[0.16em] text-emerald-100">
                        <span class="h-1.5 w-1.5 rounded-full bg-amber-300" aria-hidden="true"></span>
                        Kampala to everywhere
                    </p>
                    <h1 class="text-4xl font-bold leading-tight tracking-tight xl:text-5xl">Every journey, managed with care.</h1>
                    <p class="mt-5 max-w-md text-base leading-7 text-emerald-100/80">A secure workspace for PISFA customers, drivers, and the team coordinating travel across Uganda and beyond.</p>
                </div>

                <div class="relative flex items-center gap-3 text-sm text-emerald-200">
                    <span class="flex h-9 w-9 items-center justify-center rounded-full bg-emerald-900">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <path d="M12 21s7-4.35 7-11a7 7 0 1 0-14 0c0 6.65 7 11 7 11Z" />
                            <circle cx="12" cy="10" r="2.5" />
                        </svg>
                    </span>
                    Kampala, Uganda
                </div>
            </aside>

            <main id="main-content" class="flex min-h-screen flex-col px-4 py-6 sm:px-8 lg:px-12">
                <div class="mx-auto flex w-full max-w-lg items-center justify-between lg:justify-end">
                    <x-brand-logo :href="url('/')" size="sm" class="lg:hidden" />
                    <span class="text-xs font-medium uppercase tracking-[0.12em] text-slate-400">Secure access</span>
                </div>

                <div class="mx-auto flex w-full max-w-lg flex-1 items-center py-8 sm:py-12">
                    <section class="w-full rounded-2xl border border-slate-200 bg-white p-6 shadow-xl shadow-slate-200/60 sm:p-9" aria-label="Account access">
                        {{ $slot }}
                    </section>
                </div>

                <p class="mx-auto w-full max-w-lg text-center text-xs text-slate-400">PISFA Tour &amp; Travel &middot; Dependable journeys, thoughtful service.</p>
            </main>
        </div>

        @livewireScripts
        @stack('scripts')
    </body>
</html>
