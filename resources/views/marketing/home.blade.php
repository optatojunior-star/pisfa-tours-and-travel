@extends('layouts.public')

@section('content')
    {{--
        The header is a slideshow of the services themselves.

        It was a fixed headline beside four cards of general reassurance —
        "local insight", "responsible service" — none of which said that this
        company hires cars, sells them, imports them, runs safaris, books lodges
        and meets flights. A visitor had to scroll to find that out. The
        slideshow reads from ServiceCatalogue, the same list the menu and the
        cards below use, so it can never fall out of step with what is shipped.
    --}}
    <x-hero-slider :services="$services" :images="$serviceImages" />

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
                            @if ($image = ($serviceImages[$slug] ?? null))
                                <span class="h-12 w-12 shrink-0 overflow-hidden rounded-control bg-brand-50">
                                    <img src="{{ $image }}" alt="" loading="lazy" class="h-full w-full object-cover">
                                </span>
                            @else
                                <span class="grid h-12 w-12 shrink-0 place-items-center rounded-control bg-brand-50 text-brand-700">
                                    <x-icon :name="$service['icon']" class="h-6 w-6" />
                                </span>
                            @endif
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
