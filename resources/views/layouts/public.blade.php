<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#04574e">
    {{-- Read by the chat widget, which posts JSON rather than a form. --}}
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <x-seo-meta
        :title="$title ?? null"
        :description="$description ?? null"
        :image="$ogImage ?? null"
        :type="$ogType ?? 'website'"
        :noindex="$noindex ?? false"
        :published-at="$publishedAt ?? null" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('styles')
    <x-pwa-head />
    <x-structured-data :schema="$schema ?? null" />
    <style>[x-cloak] { display: none !important; }</style>
</head>
<body class="min-h-screen bg-stone-50 text-slate-900 antialiased">
    <a href="#main-content" class="sr-only z-50 rounded-md bg-white px-4 py-3 text-emerald-900 focus:not-sr-only focus:fixed focus:left-4 focus:top-4">
        Skip to main content
    </a>

    <header class="border-b border-emerald-900/15 bg-white">
        <div class="bg-emerald-950 px-4 py-2 text-center text-sm text-emerald-50">
            Uganda-based travel planning with clear, personal support.
        </div>
        <nav class="mx-auto flex max-w-7xl items-center justify-between gap-6 px-4 py-4 sm:px-6 lg:px-8" aria-label="Main navigation">
            <x-brand-logo :href="route('home')" size="md" />

            <div class="hidden items-center gap-1 text-sm font-semibold md:flex">
                {{--
                    Six top-level items, not thirteen. Every service used to sit
                    in the bar, which pushed the row onto two lines and made the
                    important links compete with the obscure ones. They are now
                    grouped behind Services, which is also where somebody
                    actually looks for them.
                --}}
                <a href="{{ route('home') }}" @class([
                    'rounded-control px-3 py-2 transition hover:bg-brand-50',
                    'text-accent-800' => request()->routeIs('home'),
                    'text-ink-700 hover:text-brand-800' => ! request()->routeIs('home'),
                ])>Home</a>

                @php
                    $serviceLinks = collect([
                        ['tours.index', 'Tours & safaris', 'compass', 'tours.*', 'Safaris, cultural journeys and day trips'],
                        ['car-hire.index', 'Car hire', 'car', 'car-hire*', 'Self-drive or with a driver'],
                        ['airport-transfers.index', 'Airport transfers', 'plane', 'airport-transfer*', 'Entebbe pickups and drop-offs'],
                        ['accommodation.index', 'Places to stay', 'home', 'accommodation.*', 'Lodges, hotels and longer stays'],
                        ['showroom.index', 'Cars for sale', 'tag', 'showroom.*', 'Inspected vehicles from our fleet'],
                        ['vehicle-imports.create', 'Vehicle imports', 'ship', 'vehicle-imports.*', 'Sourcing, shipping and clearance'],
                        ['flight-inquiries.create', 'Flight enquiries', 'globe', 'flight-inquiries.*', 'We find and quote the fare'],
                        ['leasing.create', 'Lease your car to us', 'handshake', 'leasing.*', 'Put your vehicle to work'],
                    ])->filter(fn ($l) => Route::has($l[0]));

                    $onService = $serviceLinks->contains(fn ($l) => request()->routeIs($l[3]));
                @endphp

                <div x-data="{ open: false }" @keydown.escape.window="open = false" class="relative">
                    <button type="button"
                            @click="open = ! open"
                            :aria-expanded="open ? 'true' : 'false'"
                            aria-haspopup="true"
                            @class([
                                'inline-flex items-center gap-1.5 rounded-control px-3 py-2 transition hover:bg-brand-50',
                                'text-accent-800' => $onService,
                                'text-ink-700 hover:text-brand-800' => ! $onService,
                            ])>
                        Services
                        <svg class="h-4 w-4 transition-transform" :class="open && 'rotate-180'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                    </button>

                    <div x-show="open" x-cloak
                         @click.outside="open = false"
                         x-transition.opacity.duration.150ms
                         class="absolute left-1/2 z-30 mt-2 w-[34rem] -translate-x-1/2 rounded-card border border-ink-200 bg-white p-2 shadow-xl">
                        <ul class="grid grid-cols-2 gap-1">
                            @foreach ($serviceLinks as [$route, $label, $icon, $pattern, $blurb])
                                <li>
                                    <a href="{{ route($route) }}" class="flex gap-3 rounded-control p-3 transition hover:bg-brand-50">
                                        <span class="mt-0.5 grid h-9 w-9 shrink-0 place-items-center rounded-control bg-brand-50 text-brand-700">
                                            <x-icon :name="$icon" class="h-5 w-5" />
                                        </span>
                                        <span>
                                            <span class="block font-bold text-ink-900">{{ $label }}</span>
                                            <span class="mt-0.5 block text-xs font-medium leading-5 text-ink-600">{{ $blurb }}</span>
                                        </span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </div>

                <a href="{{ route('about') }}" @class([
                    'rounded-control px-3 py-2 transition hover:bg-brand-50',
                    'text-accent-800' => request()->routeIs('about'),
                    'text-ink-700 hover:text-brand-800' => ! request()->routeIs('about'),
                ])>About</a>
                <a href="{{ route('blog.index') }}" @class([
                    'rounded-control px-3 py-2 transition hover:bg-brand-50',
                    'text-accent-800' => request()->routeIs('blog.*'),
                    'text-ink-700 hover:text-brand-800' => ! request()->routeIs('blog.*'),
                ])>Journal</a>
                <a href="{{ route('contact') }}" @class([
                    'rounded-control px-3 py-2 transition hover:bg-brand-50',
                    'text-accent-800' => request()->routeIs('contact'),
                    'text-ink-700 hover:text-brand-800' => ! request()->routeIs('contact'),
                ])>Contact</a>

                <span class="mx-2 h-6 w-px bg-ink-200" aria-hidden="true"></span>

                @auth
                    <a href="{{ route('dashboard') }}" class="rounded-control px-3 py-2 text-ink-700 transition hover:bg-brand-50 hover:text-brand-800">Dashboard</a>
                @else
                    <a href="{{ route('login') }}" class="rounded-control px-3 py-2 text-ink-700 transition hover:bg-brand-50 hover:text-brand-800">Sign in</a>
                @endauth
                <a href="{{ route('request-quotation') }}" class="ml-1 whitespace-nowrap rounded-full bg-brand-800 px-5 py-2.5 text-white shadow-sm transition hover:bg-brand-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-700 focus-visible:ring-offset-2">Request a quotation</a>
            </div>

            <details class="relative md:hidden">
                <summary class="cursor-pointer list-none rounded-lg border border-slate-300 px-4 py-2 font-semibold">Menu</summary>
                <div class="absolute right-0 z-20 mt-3 grid w-64 gap-1 rounded-2xl border border-slate-200 bg-white p-3 shadow-xl">
                    <a href="{{ route('home') }}" class="rounded-lg px-4 py-3 hover:bg-emerald-50">Home</a>
                    @if (Route::has('tours.index'))
                        <a href="{{ route('tours.index') }}" class="rounded-lg px-4 py-3 hover:bg-emerald-50">Tours &amp; safaris</a>
                    @endif
                    @if (Route::has('car-hire.index'))
                        <a href="{{ route('car-hire.index') }}" class="rounded-lg px-4 py-3 hover:bg-emerald-50">Car hire</a>
                    @endif
                    @if (Route::has('airport-transfers.index'))
                        <a href="{{ route('airport-transfers.index') }}" class="rounded-lg px-4 py-3 hover:bg-emerald-50">Airport transfers</a>
                    @endif
                    @if (Route::has('flight-inquiries.create'))
                        <a href="{{ route('flight-inquiries.create') }}" class="rounded-lg px-4 py-3 hover:bg-emerald-50">Flight enquiries</a>
                    @endif
                    @if (Route::has('vehicle-imports.create'))
                        <a href="{{ route('vehicle-imports.create') }}" class="rounded-lg px-4 py-3 hover:bg-emerald-50">Vehicle imports</a>
                    @endif
                    @if (Route::has('showroom.index'))
                        <a href="{{ route('showroom.index') }}" class="rounded-lg px-4 py-3 hover:bg-emerald-50">Cars for sale</a>
                    @endif
                    @if (Route::has('accommodation.index'))
                        <a href="{{ route('accommodation.index') }}" class="rounded-lg px-4 py-3 hover:bg-emerald-50">Places to stay</a>
                    @endif
                    @if (Route::has('leasing.create'))
                        <a href="{{ route('leasing.create') }}" class="rounded-lg px-4 py-3 hover:bg-emerald-50">Lease your car to us</a>
                    @endif
                    <a href="{{ route('home') }}#services" class="rounded-lg px-4 py-3 hover:bg-emerald-50">Services</a>
                    <a href="{{ route('about') }}" class="rounded-lg px-4 py-3 hover:bg-emerald-50">About</a>
                    <a href="{{ route('blog.index') }}" class="rounded-lg px-4 py-3 hover:bg-emerald-50">Journal</a>
                    <a href="{{ route('contact') }}" class="rounded-lg px-4 py-3 hover:bg-emerald-50">Contact</a>
                    @auth
                        <a href="{{ route('dashboard') }}" class="rounded-lg px-4 py-3 hover:bg-emerald-50">Dashboard</a>
                    @else
                        <a href="{{ route('login') }}" class="rounded-lg px-4 py-3 hover:bg-emerald-50">Sign in</a>
                        <a href="{{ route('register') }}" class="rounded-lg px-4 py-3 hover:bg-emerald-50">Create account</a>
                    @endauth
                    <a href="{{ route('request-quotation') }}" class="mt-2 rounded-lg bg-emerald-800 px-4 py-3 text-center font-semibold text-white">Request a quotation</a>
                </div>
            </details>
        </nav>
    </header>

    @if (session('contact_success'))
        <div class="border-b border-emerald-200 bg-emerald-50 px-4 py-4 text-emerald-950" role="status" aria-live="polite">
            <p class="mx-auto max-w-7xl font-medium">{{ session('contact_success') }}</p>
        </div>
    @endif

    <main id="main-content">
        @yield('content')
    </main>

    <footer class="bg-emerald-950 text-emerald-50">
        <div class="mx-auto grid max-w-7xl gap-10 px-4 py-16 sm:px-6 md:grid-cols-2 lg:grid-cols-4 lg:px-8">
            <section class="lg:col-span-2" aria-labelledby="footer-about">
                <h2 id="footer-about" class="text-2xl font-black">PISFA Tours and Travels</h2>
                <p class="mt-4 max-w-xl leading-7 text-emerald-100">
                    Thoughtful travel and transport planning for visitors, families, organisations, and vehicle owners across Uganda.
                </p>
                <div class="mt-6 flex flex-wrap gap-4 text-sm font-semibold">
                    <a href="{{ route('about') }}" class="hover:text-amber-300">About PISFA</a>
                    <a href="{{ route('contact') }}" class="hover:text-amber-300">Contact us</a>
                    <a href="{{ route('privacy') }}" class="hover:text-amber-300">Privacy</a>
                    <a href="{{ route('terms') }}" class="hover:text-amber-300">Terms</a>
                </div>
            </section>

            <section aria-labelledby="footer-services">
                <h2 id="footer-services" class="font-bold text-amber-300">Plan with us</h2>
                <ul class="mt-4 space-y-3 text-sm text-emerald-100">
                    <li><a href="{{ route('tours.index') }}" class="hover:text-white">Tours &amp; safaris</a></li>
                    <li><a href="{{ route('blog.index') }}" class="hover:text-white">Journal</a></li>
                    <li><a href="{{ route('car-hire.index') }}" class="hover:text-white">Car hire</a></li>
                    <li><a href="{{ route('airport-transfers.index') }}" class="hover:text-white">Airport transfers</a></li>
                    <li><a href="{{ route('flight-inquiries.create') }}" class="hover:text-white">Flight enquiries</a></li>
                    <li><a href="{{ route('vehicle-imports.create') }}" class="hover:text-white">Vehicle imports</a></li>
                    <li><a href="{{ route('showroom.index') }}" class="hover:text-white">Cars for sale</a></li>
                    <li><a href="{{ route('accommodation.index') }}" class="hover:text-white">Places to stay</a></li>
                    <li><a href="{{ route('leasing.create') }}" class="hover:text-white">Lease your car to us</a></li>
                    <li><a href="{{ route('request-quotation', ['service' => 'corporate-travel']) }}" class="hover:text-white">Corporate travel</a></li>
                </ul>
            </section>

            <section aria-labelledby="newsletter-heading">
                <h2 id="newsletter-heading" class="font-bold text-amber-300">Travel updates</h2>
                <p class="mt-4 text-sm leading-6 text-emerald-100">Receive useful PISFA news and travel ideas.</p>
                <form method="POST" action="{{ route('newsletter.store') }}" class="mt-4 space-y-3">
                    @csrf
                    <input type="hidden" name="source" value="footer">
                    <label for="newsletter-email" class="sr-only">Email address</label>
                    <input id="newsletter-email" name="email" type="email" autocomplete="email" required maxlength="254" value="{{ old('email') }}" placeholder="you@example.com" class="w-full rounded-lg border-0 bg-white px-4 py-3 text-slate-900 ring-1 ring-white/20 focus:ring-2 focus:ring-amber-400">
                    @error('email', 'newsletter')
                        <p class="text-sm text-amber-200">{{ $message }}</p>
                    @enderror
                    <button type="submit" class="w-full rounded-lg bg-amber-400 px-4 py-3 font-bold text-emerald-950 transition hover:bg-amber-300 focus:outline-none focus:ring-2 focus:ring-white focus:ring-offset-2 focus:ring-offset-emerald-950">
                        Subscribe
                    </button>
                </form>
                @if (session('newsletter_success'))
                    <p class="mt-3 text-sm font-medium text-amber-200" role="status" aria-live="polite">{{ session('newsletter_success') }}</p>
                @endif
            </section>
        </div>
        <div class="border-t border-white/10 px-4 py-6 text-center text-sm text-emerald-200">
            &copy; {{ now(config('pisfa.business_timezone'))->year }} PISFA Tours and Travels. All rights reserved.
        </div>
    </footer>

    <x-chat-widget />

    @stack('scripts')
</body>
</html>
