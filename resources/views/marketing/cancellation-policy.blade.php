@extends('layouts.public')

@php
    $title = 'Cancellation and refund policy';
    $description = 'What a cancellation costs at PISFA Tours and Travels, by service and by notice given, and how refunds are made.';

    $policy = \App\Support\LegalDocuments::cancellationPolicy();
@endphp

@section('content')
    <section class="bg-emerald-950 px-4 py-16 text-white sm:px-6 lg:px-8">
        <div class="mx-auto max-w-4xl">
            <p class="text-sm font-black uppercase tracking-[0.22em] text-amber-300">Cancellations</p>
            <h1 class="mt-4 text-4xl font-black sm:text-5xl">Cancellation and refund policy</h1>
            <p class="mt-4 max-w-2xl text-emerald-100">
                What a cancellation costs, set out plainly, so nobody has to guess.
            </p>
            <p class="mt-4 text-sm text-emerald-200">
                Last updated: {{ \App\Support\LegalDocuments::LAST_UPDATED }}
            </p>
        </div>
    </section>

    <div class="mx-auto max-w-4xl px-4 py-12 leading-8 text-slate-700 sm:px-6 lg:px-8">
        @foreach ($policy['intro'] as $paragraph)
            <p class="@if (! $loop->first) mt-4 @endif">{{ $paragraph }}</p>
        @endforeach

        <div class="mt-10 space-y-8">
            @foreach ($policy['tiers'] as $tier)
                <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white">
                    <h2 class="border-b border-slate-200 bg-slate-50 px-6 py-4 text-xl font-black text-emerald-950">
                        {{ $tier['service'] }}
                    </h2>

                    {{-- Its own scroller. On a phone this table cannot shrink
                         without the charge column becoming unreadable, and the
                         page itself must never scroll sideways. --}}
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[30rem] border-collapse text-left text-sm leading-6">
                            <thead>
                                <tr class="text-[11px] uppercase tracking-wide text-slate-500">
                                    <th scope="col" class="px-6 py-3 font-bold">If you cancel</th>
                                    <th scope="col" class="px-6 py-3 font-bold">You pay</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($tier['rows'] as $row)
                                    <tr class="border-t border-slate-200">
                                        <td class="px-6 py-3 text-slate-700">{{ $row['when'] }}</td>
                                        <td class="px-6 py-3 font-semibold text-emerald-950">{{ $row['charge'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
            @endforeach
        </div>

        <section class="mt-12">
            <h2 class="text-2xl font-black text-emerald-950">Things worth knowing</h2>
            <ul class="mt-5 space-y-4">
                @foreach ($policy['notes'] as $note)
                    <li class="flex gap-4">
                        <span aria-hidden="true" class="mt-3 h-1.5 w-1.5 shrink-0 rounded-full bg-amber-500"></span>
                        <span>{{ $note }}</span>
                    </li>
                @endforeach
            </ul>
        </section>

        <aside class="mt-12 rounded-3xl bg-amber-50 p-7 ring-1 ring-amber-200">
            <h2 class="text-xl font-black text-emerald-950">Need to cancel something?</h2>
            <p class="mt-2 text-slate-700">
                Tell us as early as you can. The earlier it reaches us, the less it costs you &mdash;
                and if the reason is a serious one, say so, because we can often help.
            </p>
            <div class="mt-4 flex flex-wrap gap-3">
                <a href="{{ route('contact') }}"
                   class="inline-flex min-h-11 items-center rounded-xl bg-emerald-800 px-5 font-bold text-white hover:bg-emerald-900">
                    Contact us
                </a>
                <a href="{{ route('booking-terms') }}"
                   class="inline-flex min-h-11 items-center rounded-xl border border-emerald-300 px-5 font-bold text-emerald-800 hover:bg-white">
                    Full booking terms
                </a>
            </div>
        </aside>
    </div>
@endsection
