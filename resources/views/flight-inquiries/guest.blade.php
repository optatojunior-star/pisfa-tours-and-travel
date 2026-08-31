@extends('layouts.public')

@section('content')
<div class="mx-auto max-w-3xl px-4 py-12 sm:px-6 lg:px-8">
    @if (session('success'))
        <div class="mb-6 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-900" role="status">{{ session('success') }}</div>
    @endif

    <article class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
        <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">Flight enquiry</p>
        <h1 class="mt-2 text-3xl font-black tracking-tight text-emerald-950">{{ $inquiry->reference }}</h1>
        <p class="mt-3 text-sm text-slate-600">Keep this reference. A travel consultant replies to <span class="font-semibold">{{ $inquiry->contact_email }}</span> with fare options.</p>

        <dl class="mt-8 grid gap-5 sm:grid-cols-2">
            <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Status</dt><dd class="mt-1 text-base font-bold text-slate-900">{{ $inquiry->status->label() }}</dd></div>
            <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Flight type</dt><dd class="mt-1 text-base font-bold text-slate-900">{{ $inquiry->scope->label() }}</dd></div>
            <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Route</dt><dd class="mt-1 text-base font-bold text-slate-900">{{ $inquiry->routeLabel() }}</dd></div>
            <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Trip</dt><dd class="mt-1 text-base font-bold text-slate-900">{{ $inquiry->trip_type->label() }}</dd></div>
            <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Outbound</dt><dd class="mt-1 text-base font-bold text-slate-900">{{ $inquiry->outbound_on->format('j M Y') }}</dd></div>
            <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Return</dt><dd class="mt-1 text-base font-bold text-slate-900">{{ $inquiry->return_on?->format('j M Y') ?? 'Not applicable' }}</dd></div>
            <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Travellers</dt><dd class="mt-1 text-base font-bold text-slate-900">{{ $inquiry->passenger_count }}</dd></div>
            <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Class</dt><dd class="mt-1 text-base font-bold text-slate-900">{{ $inquiry->travel_class->label() }}</dd></div>
            <div class="sm:col-span-2"><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Fare and seats</dt><dd class="mt-1 text-base font-bold text-amber-800">No seat is held and no payment has been collected.</dd></div>
        </dl>

        <div class="mt-8 rounded-2xl bg-stone-100 p-5 text-sm text-slate-700">
            <p class="font-bold text-slate-900">Need to change or withdraw this enquiry?</p>
            <p class="mt-1">Reply to the confirmation email or <a href="{{ route('contact') }}" class="font-bold text-emerald-800 underline decoration-amber-400 decoration-2 underline-offset-4">contact our team</a> quoting {{ $inquiry->reference }}. Create an account with the same email to follow future enquiries in your portal.</p>
        </div>

        <div class="mt-6 flex flex-wrap gap-3">
            <a href="{{ route('register') }}" class="inline-flex min-h-11 items-center justify-center rounded-xl bg-emerald-800 px-5 py-3 text-sm font-bold text-white hover:bg-emerald-900">Create an account</a>
            <a href="{{ route('flight-inquiries.create') }}" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-emerald-200 px-5 py-3 text-sm font-bold text-emerald-800 hover:bg-emerald-50">Send another enquiry</a>
        </div>
    </article>
</div>
@endsection
