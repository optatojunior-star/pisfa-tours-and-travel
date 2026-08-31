@php
    use App\Enums\AirportTransferBookingStatus;
    use App\Support\Money;

    $timezone = config('pisfa.business_timezone', 'Africa/Kampala');
@endphp
<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">Airport transfers</p>
                <h1 class="mt-1 text-2xl font-bold text-slate-950">My transfers</h1>
            </div>
            <a href="{{ route('airport-transfers.index') }}" class="inline-flex min-h-11 items-center justify-center rounded-xl bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white">Book a transfer</a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
            @if (session('success'))
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-900" role="status">{{ session('success') }}</div>
            @endif

            <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm" aria-labelledby="my-transfer-filters">
                <h2 id="my-transfer-filters" class="sr-only">Filter transfers</h2>
                <form method="GET" action="{{ route('portal.airport-transfer-bookings.index') }}" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5 lg:items-end">
                    <div class="lg:col-span-2">
                        <label for="transfer-history-search" class="block text-sm font-semibold">Search</label>
                        <input id="transfer-history-search" name="q" type="search" maxlength="100" value="{{ $filters['q'] ?? '' }}" placeholder="Reference, airport, location" class="mt-1 block w-full rounded-xl border-slate-300">
                    </div>
                    <div>
                        <label for="transfer-history-status" class="block text-sm font-semibold">Status</label>
                        <select id="transfer-history-status" name="status" class="mt-1 block w-full rounded-xl border-slate-300">
                            <option value="">All statuses</option>
                            @foreach (AirportTransferBookingStatus::cases() as $case)
                                <option value="{{ $case->value }}" @selected(($filters['status'] ?? '') === $case->value)>{{ $case->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="transfer-history-period" class="block text-sm font-semibold">Period</label>
                        <select id="transfer-history-period" name="period" class="mt-1 block w-full rounded-xl border-slate-300">
                            <option value="all">All dates</option>
                            <option value="upcoming" @selected(($filters['period'] ?? '') === 'upcoming')>Upcoming</option>
                            <option value="past" @selected(($filters['period'] ?? '') === 'past')>Past</option>
                        </select>
                    </div>
                    <div class="flex gap-2">
                        <button class="min-h-11 rounded-xl bg-emerald-700 px-4 text-sm font-bold text-white">Filter</button>
                        <a href="{{ route('portal.airport-transfer-bookings.index') }}" class="inline-flex min-h-11 items-center px-2 text-sm font-bold text-slate-600">Reset</a>
                    </div>
                </form>
            </section>

            @if ($bookings->isEmpty())
                <div class="rounded-3xl border border-dashed border-slate-300 bg-white p-10 text-center">
                    <h2 class="text-lg font-bold">No transfers found</h2>
                    <p class="mt-2 text-sm text-slate-600">Airport transfer requests you submit while signed in will appear here.</p>
                    <a href="{{ route('airport-transfers.index') }}" class="mt-5 inline-flex min-h-11 items-center justify-center rounded-xl bg-emerald-700 px-5 py-3 text-sm font-bold text-white">Plan a transfer</a>
                </div>
            @else
                <div class="grid gap-5 md:grid-cols-2">
                    @foreach ($bookings as $booking)
                        <article class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <p class="font-mono text-xs font-bold text-emerald-700">{{ $booking->reference }}</p>
                                    <h2 class="mt-1 text-lg font-black text-slate-950">{{ $booking->transfer_type->label() }}</h2>
                                    <p class="text-sm text-slate-600">{{ $booking->airport_code_snapshot }} · {{ $booking->location_name_snapshot }}</p>
                                </div>
                                <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-700">{{ $booking->status->label() }}</span>
                            </div>
                            <dl class="mt-4 grid grid-cols-2 gap-3 text-sm">
                                <div>
                                    <dt class="text-xs text-slate-500">Service time</dt>
                                    <dd class="font-semibold">{{ $booking->service_starts_at->timezone($timezone)->format('j M Y, H:i') }}</dd>
                                </div>
                                <div>
                                    <dt class="text-xs text-slate-500">Vehicle class</dt>
                                    <dd class="font-semibold">{{ str($booking->vehicle_type_snapshot)->replace('_', ' ')->title() }}</dd>
                                </div>
                                <div>
                                    <dt class="text-xs text-slate-500">Amount</dt>
                                    <dd class="font-semibold">{{ Money::format((int) $booking->amount_minor, $booking->currency) }}</dd>
                                </div>
                                <div>
                                    <dt class="text-xs text-slate-500">Payment</dt>
                                    <dd class="font-semibold text-amber-800">Not collected online</dd>
                                </div>
                            </dl>
                            <a href="{{ route('portal.airport-transfer-bookings.show', $booking) }}" class="mt-5 inline-flex min-h-11 w-full items-center justify-center rounded-xl border border-emerald-200 text-sm font-bold text-emerald-800">View transfer</a>
                        </article>
                    @endforeach
                </div>
                <div>{{ $bookings->links() }}</div>
            @endif
        </div>
    </div>
</x-app-layout>
