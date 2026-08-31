@extends('layouts.public')

@section('content')
    <section class="relative overflow-hidden bg-emerald-950 text-white">
        <div class="absolute inset-0 opacity-20" aria-hidden="true">
            <div class="absolute -right-32 -top-32 size-96 rounded-full bg-amber-300 blur-3xl"></div>
            <div class="absolute -bottom-36 -left-20 size-96 rounded-full bg-emerald-400 blur-3xl"></div>
        </div>
        <div class="relative mx-auto grid max-w-7xl items-center gap-12 px-4 py-20 sm:px-6 sm:py-28 lg:grid-cols-2 lg:px-8 lg:py-32">
            <div>
                <p class="text-sm font-black uppercase tracking-[0.22em] text-amber-300">Discover Uganda with confidence</p>
                <h1 class="mt-5 text-4xl font-black leading-tight sm:text-5xl lg:text-6xl">Journeys planned around people, not packages.</h1>
                <p class="mt-6 max-w-2xl text-lg leading-8 text-emerald-100">
                    PISFA brings tours, transport, vehicles, stays, and group travel planning together with responsive local support.
                </p>
                <div class="mt-9 flex flex-col gap-4 sm:flex-row">
                    <a href="#services" class="rounded-xl bg-amber-400 px-6 py-4 text-center font-bold text-emerald-950 transition hover:bg-amber-300 focus:outline-none focus:ring-2 focus:ring-white">Explore services</a>
                    <a href="{{ route('request-quotation') }}" class="rounded-xl border border-white/40 px-6 py-4 text-center font-bold text-white transition hover:bg-white/10 focus:outline-none focus:ring-2 focus:ring-amber-400">Tell us what you need</a>
                </div>
            </div>
            <div class="grid gap-4 sm:grid-cols-2" aria-label="PISFA service highlights">
                <div class="rounded-3xl bg-white/10 p-6 backdrop-blur-sm ring-1 ring-white/15">
                    <x-icon name="globe" class="h-9 w-9 text-accent-400" />
                    <h2 class="mt-6 text-xl font-bold">Local insight</h2>
                    <p class="mt-2 text-sm leading-6 text-emerald-100">Plans shaped with practical knowledge of Uganda and its routes.</p>
                </div>
                {{-- Icon and text are ink on the orange ground: an accent-coloured
                     icon here was orange on orange and effectively invisible. --}}
                <div class="rounded-3xl bg-accent-500 p-6 text-ink-950 sm:translate-y-8">
                    <x-icon name="compass" class="h-9 w-9 text-ink-950" />
                    <h2 class="mt-6 text-xl font-bold">One helpful team</h2>
                    <p class="mt-2 text-sm leading-6">A clear starting point for several connected travel services.</p>
                </div>
                <div class="rounded-3xl bg-white/10 p-6 backdrop-blur-sm ring-1 ring-white/15">
                    <x-icon name="chat" class="h-9 w-9 text-accent-400" />
                    <h2 class="mt-6 text-xl font-bold">Real follow-up</h2>
                    <p class="mt-2 text-sm leading-6 text-emerald-100">Website inquiries are saved for review instead of showing a fake success.</p>
                </div>
                <div class="rounded-3xl bg-white/10 p-6 backdrop-blur-sm ring-1 ring-white/15 sm:translate-y-8">
                    <x-icon name="shield" class="h-9 w-9 text-accent-400" />
                    <h2 class="mt-6 text-xl font-bold">Responsible service</h2>
                    <p class="mt-2 text-sm leading-6 text-emerald-100">Prices and availability are checked again before anything is confirmed.</p>
                </div>
            </div>
        </div>
    </section>

    <section id="services" class="scroll-mt-8 px-4 py-20 sm:px-6 lg:px-8">
        <div class="mx-auto max-w-7xl">
            <div class="max-w-3xl">
                <p class="text-sm font-black uppercase tracking-[0.2em] text-amber-700">Service catalogue</p>
                <h2 class="mt-3 text-3xl font-black text-emerald-950 sm:text-4xl">A connected way to plan the road ahead</h2>
                <p class="mt-5 text-lg leading-8 text-slate-600">
                    Tours, car hire, airport transfers, stays, vehicle imports and cars for sale can all be browsed and requested here. Corporate and group travel is arranged with our team, because agreed rates and credit terms are set up by a person — that card leads to a quotation request we answer directly.
                </p>
            </div>

            <div class="mt-12 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($services as $slug => $service)
                    <article class="flex flex-col rounded-3xl bg-white p-6 shadow-sm ring-1 ring-slate-200 transition hover:-translate-y-1 hover:shadow-lg">
                        <div class="flex items-start justify-between gap-4">
                            <span class="grid h-12 w-12 shrink-0 place-items-center rounded-control bg-brand-50 text-brand-700">
                                <x-icon :name="$service['icon']" class="h-6 w-6" />
                            </span>
                            {{--
                                The badge follows the presence of a route in
                                ServiceCatalogue, so a shipped module cannot sit
                                on the homepage still advertised as unavailable.
                            --}}
                            @if(isset($service['route']))
                                <span class="rounded-full bg-emerald-100 px-3 py-1 text-xs font-bold text-emerald-900">Available online</span>
                            @else
                                <span class="rounded-full bg-amber-100 px-3 py-1 text-xs font-bold text-amber-900">Arranged with our team</span>
                            @endif
                        </div>
                        <h3 class="mt-7 text-xl font-bold text-emerald-950">{{ $service['name'] }}</h3>
                        <p class="mt-3 flex-1 text-sm leading-6 text-slate-600">{{ $service['summary'] }}</p>
                        <a href="{{ isset($service['route']) ? route($service['route']) : route('request-quotation', ['service' => $slug]) }}" class="mt-6 font-bold text-emerald-800 underline decoration-amber-400 decoration-2 underline-offset-4 hover:text-emerald-950">
                            {{ $service['action'] ?? 'Discuss this service' }}
                            <svg class="inline-block h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                        </a>
                    </article>
                @endforeach
            </div>
        </div>
    </section>

    <section class="bg-amber-50 px-4 py-20 sm:px-6 lg:px-8">
        <div class="mx-auto grid max-w-7xl gap-12 lg:grid-cols-[0.8fr_1.2fr] lg:items-center">
            <div>
                <p class="text-sm font-black uppercase tracking-[0.2em] text-amber-700">How it works today</p>
                <h2 class="mt-3 text-3xl font-black text-emerald-950 sm:text-4xl">Start with a real conversation</h2>
                <p class="mt-5 leading-7 text-slate-700">Browse live tours and hire vehicles online, or send a saved quotation request for services that still need direct planning with the team.</p>
            </div>
            <ol class="grid gap-5 sm:grid-cols-3">
                <li class="rounded-2xl bg-white p-6 ring-1 ring-amber-200">
                    <span class="font-black text-amber-700">01</span>
                    <h3 class="mt-4 font-bold text-emerald-950">Describe your plan</h3>
                    <p class="mt-2 text-sm leading-6 text-slate-600">Share dates, destination, travelers, and priorities.</p>
                </li>
                <li class="rounded-2xl bg-white p-6 ring-1 ring-amber-200">
                    <span class="font-black text-amber-700">02</span>
                    <h3 class="mt-4 font-bold text-emerald-950">We review it</h3>
                    <p class="mt-2 text-sm leading-6 text-slate-600">Your request is stored for the PISFA team to assess.</p>
                </li>
                <li class="rounded-2xl bg-white p-6 ring-1 ring-amber-200">
                    <span class="font-black text-amber-700">03</span>
                    <h3 class="mt-4 font-bold text-emerald-950">Plan together</h3>
                    <p class="mt-2 text-sm leading-6 text-slate-600">The team follows up using the details you provide.</p>
                </li>
            </ol>
        </div>
    </section>

    @if ($featuredPosts->isNotEmpty())
        <section class="bg-stone-50 px-4 py-20 sm:px-6 lg:px-8" aria-labelledby="journal-heading">
            <div class="mx-auto max-w-7xl">
                <div class="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h2 id="journal-heading" class="text-3xl font-black text-emerald-950">From the journal</h2>
                        <p class="mt-2 text-slate-600">Route guides and practical advice from the people who run the trips.</p>
                    </div>
                    <a href="{{ route('blog.index') }}" class="font-bold text-emerald-800 underline decoration-amber-400 decoration-2 underline-offset-4">Read the journal</a>
                </div>

                <div class="mt-8 grid gap-6 md:grid-cols-3">
                    @foreach ($featuredPosts as $post)
                        @include('blog.partials.card', [
                            'post' => $post,
                            'timezone' => config('pisfa.business_timezone', 'Africa/Kampala'),
                        ])
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    <section class="px-4 py-20 sm:px-6 lg:px-8">
        <div class="mx-auto flex max-w-7xl flex-col items-start justify-between gap-8 rounded-3xl bg-emerald-800 p-8 text-white shadow-xl sm:p-12 lg:flex-row lg:items-center">
            <div>
                <p class="font-bold text-amber-300">Ready to begin?</p>
                <h2 class="mt-2 text-3xl font-black">Tell us where you want to go.</h2>
                <p class="mt-3 max-w-2xl leading-7 text-emerald-100">Send a saved inquiry and give our team the context needed for a useful response.</p>
            </div>
            <a href="{{ route('request-quotation') }}" class="shrink-0 rounded-xl bg-amber-400 px-7 py-4 font-bold text-emerald-950 hover:bg-amber-300 focus:outline-none focus:ring-2 focus:ring-white">Request a quotation</a>
        </div>
    </section>
@endsection
