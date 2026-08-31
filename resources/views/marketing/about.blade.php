@extends('layouts.public')

@php
    $title = 'About us';
    $description = 'Learn about PISFA Tours and Travels and the principles guiding its Uganda-based travel and transport services.';
@endphp

@section('content')
    <section class="bg-emerald-950 px-4 py-20 text-white sm:px-6 lg:px-8">
        <div class="mx-auto max-w-5xl text-center">
            <p class="text-sm font-black uppercase tracking-[0.22em] text-amber-300">About PISFA</p>
            <h1 class="mt-5 text-4xl font-black sm:text-5xl">Travel support built around trust, local knowledge, and useful service.</h1>
            <p class="mx-auto mt-6 max-w-3xl text-lg leading-8 text-emerald-100">
                PISFA Tours and Travels is developing one connected home for planning journeys, transport, vehicles, and stays in Uganda.
            </p>
        </div>
    </section>

    <section class="px-4 py-20 sm:px-6 lg:px-8">
        <div class="mx-auto grid max-w-7xl gap-12 lg:grid-cols-2 lg:items-start">
            <div>
                <p class="text-sm font-black uppercase tracking-[0.2em] text-amber-700">Our direction</p>
                <h2 class="mt-3 text-3xl font-black text-emerald-950">Make complex plans feel manageable</h2>
                <div class="mt-6 space-y-5 leading-8 text-slate-700">
                    <p>Travel often involves several connected decisions: where to go, how to move, where to stay, what documents are needed, and who will help when plans change.</p>
                    <p>PISFA's planned platform brings those conversations into one service environment while retaining human follow-up. The first public release provides honest service information and stores genuine requests for review.</p>
                    <p>Transactional booking, payment, fleet, customer, and partner modules will be introduced only when each workflow is secure, tested, and ready to use end to end.</p>
                </div>
            </div>
            <div class="grid gap-5 sm:grid-cols-2">
                <article class="rounded-3xl bg-emerald-50 p-7 ring-1 ring-emerald-100">
                    <x-icon name="heart-hands" class="h-8 w-8 text-brand-700" />
                    <h3 class="mt-5 text-xl font-bold text-emerald-950">Service</h3>
                    <p class="mt-3 leading-7 text-slate-600">Listen carefully, communicate clearly, and help people make informed choices.</p>
                </article>
                <article class="rounded-3xl bg-amber-50 p-7 ring-1 ring-amber-100">
                    <x-icon name="leaf" class="h-8 w-8 text-brand-700" />
                    <h3 class="mt-5 text-xl font-bold text-emerald-950">Responsibility</h3>
                    <p class="mt-3 leading-7 text-slate-600">Build durable relationships and treat personal information with care.</p>
                </article>
                <article class="rounded-3xl bg-amber-50 p-7 ring-1 ring-amber-100">
                    <x-icon name="pin" class="h-8 w-8 text-brand-700" />
                    <h3 class="mt-5 text-xl font-bold text-emerald-950">Local context</h3>
                    <p class="mt-3 leading-7 text-slate-600">Plan with a grounded understanding of Ugandan destinations and travel realities.</p>
                </article>
                <article class="rounded-3xl bg-emerald-50 p-7 ring-1 ring-emerald-100">
                    <x-icon name="check-circle" class="h-8 w-8 text-brand-700" />
                    <h3 class="mt-5 text-xl font-bold text-emerald-950">Clarity</h3>
                    <p class="mt-3 leading-7 text-slate-600">Label what is available today and what remains part of the planned platform.</p>
                </article>
            </div>
        </div>
    </section>

    <section class="bg-amber-50 px-4 py-16 sm:px-6 lg:px-8">
        <div class="mx-auto flex max-w-5xl flex-col items-start justify-between gap-7 sm:flex-row sm:items-center">
            <div>
                <h2 class="text-2xl font-black text-emerald-950">Have a journey in mind?</h2>
                <p class="mt-2 text-slate-700">Share the details and let the PISFA team review your request.</p>
            </div>
            <a href="{{ route('request-quotation') }}" class="rounded-xl bg-emerald-800 px-6 py-4 font-bold text-white hover:bg-emerald-900 focus:outline-none focus:ring-2 focus:ring-amber-400 focus:ring-offset-2">Request a quotation</a>
        </div>
    </section>
@endsection
