@extends('layouts.public')

@php
    use App\Support\Money;

    $timezone = config('pisfa.business_timezone', 'Africa/Kampala');
@endphp

@section('content')
<div class="mx-auto max-w-3xl px-4 py-12 sm:px-6 lg:px-8">
    @if (session('success'))
        <div class="mb-6 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-900" role="status">{{ session('success') }}</div>
    @endif

    <article class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
        <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">Airport transfer request</p>
        <h1 class="mt-2 text-3xl font-black tracking-tight text-emerald-950">{{ $booking->reference }}</h1>
        <p class="mt-3 text-sm text-slate-600">Keep this reference. Our team reviews the request and replies to <span class="font-semibold">{{ $booking->contact_email }}</span> with the confirmed vehicle and driver.</p>

        <dl class="mt-8 grid gap-5 sm:grid-cols-2">
            <div>
                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Status</dt>
                <dd class="mt-1 text-base font-bold text-slate-900">{{ $booking->status->label() }}</dd>
            </div>
            <div>
                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Direction</dt>
                <dd class="mt-1 text-base font-bold text-slate-900">{{ $booking->transfer_type->label() }}</dd>
            </div>
            <div>
                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Airport</dt>
                <dd class="mt-1 text-base font-bold text-slate-900">{{ $booking->airport_code_snapshot }} — {{ $booking->airport_name_snapshot }}</dd>
            </div>
            <div>
                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Service location</dt>
                <dd class="mt-1 text-base font-bold text-slate-900">{{ $booking->location_name_snapshot }}</dd>
            </div>
            <div>
                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Service time ({{ $timezone }})</dt>
                <dd class="mt-1 text-base font-bold text-slate-900">{{ $booking->service_starts_at->timezone($timezone)->format('j M Y, H:i') }}</dd>
            </div>
            <div>
                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Scheduled flight</dt>
                <dd class="mt-1 text-base font-bold text-slate-900">{{ $booking->flight_scheduled_at->timezone($timezone)->format('j M Y, H:i') }}</dd>
            </div>
            <div>
                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Vehicle class</dt>
                <dd class="mt-1 text-base font-bold text-slate-900">{{ str($booking->vehicle_type_snapshot)->replace('_', ' ')->title() }}</dd>
            </div>
            <div>
                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Party</dt>
                <dd class="mt-1 text-base font-bold text-slate-900">{{ $booking->passenger_count }} passenger(s), {{ $booking->luggage_count }} luggage piece(s)</dd>
            </div>
            <div>
                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Quoted amount</dt>
                <dd class="mt-1 text-base font-bold text-emerald-800">{{ Money::format((int) $booking->amount_minor, $booking->currency) }}</dd>
            </div>
            <div>
                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Payment</dt>
                <dd class="mt-1 text-base font-bold text-amber-800">Not collected online</dd>
            </div>
        </dl>

        {{--
            Guests get the driver details too.

            This page said "our team replies with the confirmed vehicle and
            driver" and then never showed them — so a guest who kept the link
            had no way to check, and the email was the only record. The link is
            already signed and specific to this booking, so there is nothing to
            withhold.
        --}}
        <div class="mt-8">
            @include('airport-transfers.partials.driver-card', ['booking' => $booking])
        </div>

        <div class="mt-8 rounded-2xl bg-stone-100 p-5 text-sm text-slate-700">
            <p class="font-bold text-slate-900">Need to change or cancel this request?</p>
            <p class="mt-1">Reply to the confirmation email or <a href="{{ route('contact') }}" class="font-bold text-emerald-800 underline decoration-amber-400 decoration-2 underline-offset-4">contact our team</a> quoting {{ $booking->reference }}. Create an account with the same email to manage future transfers in your portal.</p>
        </div>

        <div class="mt-6 flex flex-wrap gap-3">
            <a href="{{ route('register') }}" class="inline-flex min-h-11 items-center justify-center rounded-xl bg-emerald-800 px-5 py-3 text-sm font-bold text-white hover:bg-emerald-900">Create an account</a>
            <a href="{{ route('airport-transfers.index') }}" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-emerald-200 px-5 py-3 text-sm font-bold text-emerald-800 hover:bg-emerald-50">Book another transfer</a>
        </div>
    </article>
</div>
@endsection
