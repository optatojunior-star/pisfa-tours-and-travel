@php
    $timezone = config('pisfa.business_timezone', 'Africa/Kampala');
@endphp
<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">Flight enquiry</p>
                <h1 class="mt-1 font-mono text-2xl font-bold text-slate-950">{{ $inquiry->reference }}</h1>
            </div>
            <a href="{{ route('portal.flight-inquiries.index') }}" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700">Back to my enquiries</a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-4xl space-y-6 px-4 sm:px-6 lg:px-8">
            @if (session('success'))
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-900" role="status">{{ session('success') }}</div>
            @endif

            <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm" aria-labelledby="flight-summary">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 id="flight-summary" class="text-lg font-black text-slate-950">{{ $inquiry->routeLabel() }}</h2>
                    <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-700">{{ $inquiry->status->label() }}</span>
                </div>

                <dl class="mt-6 grid gap-5 sm:grid-cols-2">
                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Flight type</dt><dd class="mt-1 font-bold text-slate-900">{{ $inquiry->scope->label() }}</dd></div>
                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Trip</dt><dd class="mt-1 font-bold text-slate-900">{{ $inquiry->trip_type->label() }}</dd></div>
                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Outbound</dt><dd class="mt-1 font-bold text-slate-900">{{ $inquiry->outbound_on->format('j M Y') }}</dd></div>
                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Return</dt><dd class="mt-1 font-bold text-slate-900">{{ $inquiry->return_on?->format('j M Y') ?? 'Not applicable' }}</dd></div>
                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Travellers</dt><dd class="mt-1 font-bold text-slate-900">{{ $inquiry->passenger_count }}</dd></div>
                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Class</dt><dd class="mt-1 font-bold text-slate-900">{{ $inquiry->travel_class->label() }}</dd></div>
                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Consultant</dt><dd class="mt-1 font-bold text-slate-900">{{ $inquiry->assignee === null ? 'Being allocated' : $inquiry->assignee->name }}</dd></div>
                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Fare and seats</dt><dd class="mt-1 font-bold text-amber-800">No seat held, no payment collected</dd></div>
                    @if (filled($inquiry->notes))
                        <div class="sm:col-span-2"><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Your notes</dt><dd class="mt-1 text-slate-800">{{ $inquiry->notes }}</dd></div>
                    @endif
                    @if (filled($inquiry->resolution_reason))
                        <div class="sm:col-span-2"><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Outcome note</dt><dd class="mt-1 text-slate-800">{{ $inquiry->resolution_reason }}</dd></div>
                    @endif
                </dl>
            </section>

            <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm" aria-labelledby="flight-history">
                <h2 id="flight-history" class="text-lg font-black text-slate-950">Contact history</h2>
                @if ($inquiry->entries->isEmpty())
                    <p class="mt-3 text-sm text-slate-600">Nothing recorded yet. When a consultant emails or calls you about this enquiry, the contact appears here.</p>
                @else
                    <ol class="mt-4 space-y-3">
                        @foreach ($inquiry->entries as $entry)
                            <li class="rounded-2xl border border-slate-200 p-4 text-sm">
                                <p class="text-xs font-bold uppercase tracking-wide text-emerald-700">{{ $entry->entry_type->label() }}</p>
                                <p class="mt-1 text-slate-800">{{ $entry->body }}</p>
                                <p class="mt-2 text-xs text-slate-500">{{ $entry->created_at->timezone($timezone)->format('j M Y, H:i') }}@if ($entry->author !== null) · {{ $entry->author->name }}@endif</p>
                            </li>
                        @endforeach
                    </ol>
                @endif
            </section>

            <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm" aria-labelledby="flight-next-steps">
                <h2 id="flight-next-steps" class="text-lg font-black text-slate-950">Change or withdraw this enquiry</h2>
                <p class="mt-2 text-sm text-slate-600">Dates, routes, and traveller counts are updated by your consultant so a quoted fare stays consistent. <a href="{{ route('contact') }}" class="font-bold text-emerald-800 underline decoration-amber-400 decoration-2 underline-offset-4">Contact our team</a> quoting {{ $inquiry->reference }}, or <a href="{{ route('flight-inquiries.create') }}" class="font-bold text-emerald-800 underline decoration-amber-400 decoration-2 underline-offset-4">send a new enquiry</a>.</p>
            </section>
        </div>
    </div>
</x-app-layout>
