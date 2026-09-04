@extends('layouts.public')

@php
    $title = 'Booking terms and conditions';
    $description = 'The terms on which PISFA Tours and Travels provides tours, car hire, airport transfers, accommodation, vehicle imports, sales and leasing.';
@endphp

@section('content')
    <section class="bg-emerald-950 px-4 py-16 text-white sm:px-6 lg:px-8">
        <div class="mx-auto max-w-4xl">
            <p class="text-sm font-black uppercase tracking-[0.22em] text-amber-300">Booking terms</p>
            <h1 class="mt-4 text-4xl font-black sm:text-5xl">Terms and conditions of service</h1>
            <p class="mt-4 max-w-2xl text-emerald-100">
                What you can expect from us, and what we need from you. These apply to every booking,
                however it was made &mdash; on this website, on the telephone, or over WhatsApp.
            </p>
            <p class="mt-4 text-sm text-emerald-200">
                Last updated: {{ \App\Support\LegalDocuments::LAST_UPDATED }}
                &middot; Version {{ \App\Support\LegalDocuments::VERSION }}
            </p>
        </div>
    </section>

    <div class="mx-auto max-w-4xl px-4 py-12 sm:px-6 lg:px-8">
        {{-- A contents list, because this is long and most people arrive
             wanting one specific answer about one specific service. --}}
        <nav aria-labelledby="contents-heading" class="rounded-3xl border border-slate-200 bg-white p-6">
            <h2 id="contents-heading" class="text-sm font-black uppercase tracking-[0.12em] text-emerald-700">On this page</h2>
            <ul class="mt-3 grid gap-x-6 gap-y-2 sm:grid-cols-2">
                @foreach (\App\Support\LegalDocuments::bookingTerms() as $key => $section)
                    <li>
                        <a href="#{{ $key }}" class="font-semibold text-emerald-800 underline decoration-amber-400 decoration-2 underline-offset-4">
                            {{ $section['title'] }}
                        </a>
                    </li>
                @endforeach
                <li>
                    <a href="{{ route('cancellation-policy') }}" class="font-semibold text-emerald-800 underline decoration-amber-400 decoration-2 underline-offset-4">
                        Cancellations and refunds
                    </a>
                </li>
            </ul>
        </nav>

        <article class="mt-10 space-y-12 leading-8 text-slate-700">
            @foreach (\App\Support\LegalDocuments::bookingTerms() as $key => $section)
                <section id="{{ $key }}" class="scroll-mt-24">
                    <h2 class="text-2xl font-black text-emerald-950">{{ $section['title'] }}</h2>
                    <p class="mt-2 text-slate-600">{{ $section['intro'] }}</p>

                    {{-- Numbered, so a clause can be referred to by number in an
                         email or across a desk without anybody counting down
                         the page. --}}
                    <ol class="mt-5 space-y-4">
                        @foreach ($section['clauses'] as $index => $clause)
                            <li class="flex gap-4">
                                <span class="shrink-0 font-mono text-sm font-bold text-amber-700">
                                    {{ $loop->parent->iteration }}.{{ $index + 1 }}
                                </span>
                                <span>{{ $clause }}</span>
                            </li>
                        @endforeach
                    </ol>
                </section>
            @endforeach
        </article>

        <aside class="mt-12 rounded-3xl bg-amber-50 p-7 ring-1 ring-amber-200">
            <h2 class="text-xl font-black text-emerald-950">Something here unclear?</h2>
            <p class="mt-2 text-slate-700">
                Ask before you book rather than after. We would far rather explain a clause now than
                argue about it later.
            </p>
            <div class="mt-4 flex flex-wrap gap-3">
                <a href="{{ route('contact') }}"
                   class="inline-flex min-h-11 items-center rounded-xl bg-emerald-800 px-5 font-bold text-white hover:bg-emerald-900">
                    Contact us
                </a>
                <a href="{{ route('cancellation-policy') }}"
                   class="inline-flex min-h-11 items-center rounded-xl border border-emerald-300 px-5 font-bold text-emerald-800 hover:bg-white">
                    Cancellation policy
                </a>
            </div>
        </aside>
    </div>
@endsection
