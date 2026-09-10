@php
    use App\Support\Money;

    $timezone = config('pisfa.business_timezone', 'Africa/Kampala');
@endphp
<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">Airport transfer</p>
                <h1 class="mt-1 font-mono text-2xl font-bold text-slate-950">{{ $booking->reference }}</h1>
            </div>
            <a href="{{ route('portal.airport-transfer-bookings.index') }}" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700">Back to my transfers</a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-5xl space-y-6 px-4 sm:px-6 lg:px-8">
            @if (session('success'))
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-900" role="status">{{ session('success') }}</div>
            @endif
            @if ($errors->any())
                <div class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800" role="alert">
                    <p class="font-bold">This request could not be completed.</p>
                    <ul class="mt-2 list-disc pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                </div>
            @endif

            <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm" aria-labelledby="transfer-summary">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 id="transfer-summary" class="text-lg font-black text-slate-950">{{ $booking->transfer_type->label() }}</h2>
                    <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-700">{{ $booking->status->label() }}</span>
                </div>

                <dl class="mt-6 grid gap-5 sm:grid-cols-2">
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Airport</dt>
                        <dd class="mt-1 font-bold text-slate-900">{{ $booking->airport_code_snapshot }} — {{ $booking->airport_name_snapshot }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Service location</dt>
                        <dd class="mt-1 font-bold text-slate-900">{{ $booking->location_name_snapshot }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Service time ({{ $timezone }})</dt>
                        <dd class="mt-1 font-bold text-slate-900">{{ $booking->service_starts_at->timezone($timezone)->format('j M Y, H:i') }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Scheduled flight</dt>
                        <dd class="mt-1 font-bold text-slate-900">{{ $booking->flight_scheduled_at->timezone($timezone)->format('j M Y, H:i') }}@if (filled($booking->flight_number)) · {{ $booking->flight_number }}@endif</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Address</dt>
                        <dd class="mt-1 font-bold text-slate-900">{{ $booking->service_address }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Party</dt>
                        <dd class="mt-1 font-bold text-slate-900">{{ $booking->passenger_count }} passenger(s), {{ $booking->luggage_count }} luggage piece(s)</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Vehicle class</dt>
                        <dd class="mt-1 font-bold text-slate-900">{{ str($booking->vehicle_type_snapshot)->replace('_', ' ')->title() }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Amount</dt>
                        <dd class="mt-1 font-bold text-emerald-800">{{ Money::format((int) $booking->amount_minor, $booking->currency) }}</dd>
                    </div>
                    @if (filled($booking->special_requests))
                        <div class="sm:col-span-2">
                            <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Special requests</dt>
                            <dd class="mt-1 text-slate-800">{{ $booking->special_requests }}</dd>
                        </div>
                    @endif
                    @if (filled($booking->cancellation_reason))
                        <div class="sm:col-span-2">
                            <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Reason on file</dt>
                            <dd class="mt-1 text-slate-800">{{ $booking->cancellation_reason }}</dd>
                        </div>
                    @endif
                </dl>
            </section>

            <x-pay-now :payable="$booking" />

            @include('airport-transfers.partials.driver-card', ['booking' => $booking])

            <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm" aria-labelledby="transfer-cancel">
                <h2 id="transfer-cancel" class="text-lg font-black text-slate-950">Cancel this transfer</h2>
                @if ($canCancel)
                    <p class="mt-2 text-sm text-slate-600">You can cancel until {{ $booking->cancellation_cutoff_at->timezone($timezone)->format('j M Y, H:i') }} ({{ $timezone }}). After that, contact our team.</p>
                    <form method="POST" action="{{ route('portal.airport-transfer-bookings.cancel', $booking) }}" class="mt-4 space-y-4">
                        @csrf
                        @method('PATCH')
                        <div>
                            <label for="cancel-reason" class="block text-sm font-semibold text-slate-800">Reason</label>
                            <textarea id="cancel-reason" name="reason" rows="3" required minlength="5" maxlength="500" class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">{{ old('reason') }}</textarea>
                            <x-input-error :messages="$errors->get('reason')" class="mt-1" />
                        </div>
                        <div class="flex items-start gap-3">
                            <input id="confirm-cancellation" name="confirm_cancellation" type="checkbox" value="1" required class="mt-0.5 size-5 rounded border-slate-400 text-rose-700 focus:ring-rose-600">
                            <label for="confirm-cancellation" class="text-sm text-slate-700">I confirm I want to cancel this airport transfer.</label>
                        </div>
                        <x-input-error :messages="$errors->get('confirm_cancellation')" class="mt-1" />
                        <button type="submit" class="inline-flex min-h-11 items-center justify-center rounded-xl bg-rose-700 px-5 py-3 text-sm font-bold text-white transition hover:bg-rose-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-600 focus-visible:ring-offset-2">Cancel transfer</button>
                    </form>
                @else
                    <p class="mt-2 text-sm text-slate-600">This transfer can no longer be cancelled online. <a href="{{ route('contact') }}" class="font-bold text-emerald-800 underline decoration-amber-400 decoration-2 underline-offset-4">Contact our team</a> quoting {{ $booking->reference }}.</p>
                @endif
            </section>
        </div>
    </div>
</x-app-layout>
