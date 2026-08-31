<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">Customer portal</p>
                <h1 class="mt-1 text-2xl font-bold tracking-tight text-slate-950">My tour bookings</h1>
            </div>
            <a href="{{ route('tours.index') }}" class="inline-flex min-h-11 items-center justify-center rounded-xl bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white shadow-sm hover:bg-emerald-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2">Browse tours</a>
        </div>
    </x-slot>

    <div class="py-8 sm:py-10">
        <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
            @if (session('success') || session('status'))
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-950" role="status" aria-live="polite">{{ session('success') ?? session('status') }}</div>
            @endif
            @if ($errors->any())
                <div class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800" role="alert">
                    <p class="font-bold">We could not update the booking list.</p>
                    <ul class="mt-2 list-disc space-y-1 pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                </div>
            @endif

            <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6" aria-labelledby="booking-filters-heading">
                <h2 id="booking-filters-heading" class="sr-only">Filter my bookings</h2>
                <form method="GET" action="{{ route('portal.bookings.index') }}" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-[minmax(14rem,1fr)_12rem_12rem_auto] lg:items-end">
                    <div>
                        <label for="my-booking-search" class="block text-sm font-semibold text-slate-800">Search</label>
                        <input id="my-booking-search" name="q" type="search" maxlength="100" value="{{ request('q') }}" placeholder="Reference, tour, destination" class="mt-1 block w-full rounded-xl border-slate-300 shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                    </div>
                    <div>
                        <label for="my-booking-status" class="block text-sm font-semibold text-slate-800">Status</label>
                        <select id="my-booking-status" name="status" class="mt-1 block w-full rounded-xl border-slate-300 shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                            <option value="">All statuses</option>
                            @foreach (\App\Enums\TourBookingStatus::cases() as $status)
                                <option value="{{ $status->value }}" @selected(request('status') === $status->value)>{{ $status->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="my-booking-period" class="block text-sm font-semibold text-slate-800">Travel period</label>
                        <select id="my-booking-period" name="period" class="mt-1 block w-full rounded-xl border-slate-300 shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                            <option value="">All dates</option>
                            <option value="upcoming" @selected(request('period') === 'upcoming')>Upcoming</option>
                            <option value="past" @selected(request('period') === 'past')>Past</option>
                        </select>
                    </div>
                    <div class="flex gap-2">
                        <button type="submit" class="inline-flex min-h-11 items-center justify-center rounded-xl bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-emerald-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600">Filter</button>
                        <a href="{{ route('portal.bookings.index') }}" class="inline-flex min-h-11 items-center rounded-xl px-3 py-2.5 text-sm font-bold text-slate-600 hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600">Reset</a>
                    </div>
                </form>
            </section>

            <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm" aria-labelledby="my-bookings-heading">
                <div class="border-b border-slate-200 px-5 py-5 sm:px-6">
                    <h2 id="my-bookings-heading" class="text-lg font-bold text-slate-950">Booking requests</h2>
                    <p class="mt-1 text-sm text-slate-500">{{ $bookings->total() }} {{ str('booking')->plural($bookings->total()) }}</p>
                </div>

                @if ($bookings->isEmpty())
                    <div class="px-6 py-14 text-center">
                        <svg class="mx-auto h-12 w-12 text-slate-300" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M6 3v3m12-3v3M4 9h16M5 5h14a1 1 0 0 1 1 1v14H4V6a1 1 0 0 1 1-1Z"/><path d="M8 13h3v3H8z"/></svg>
                        <h3 class="mt-4 font-bold text-slate-900">No bookings match this view</h3>
                        <p class="mt-2 text-sm text-slate-600">Your tour booking requests will appear here after submission.</p>
                        <a href="{{ route('tours.index') }}" class="mt-6 inline-flex rounded-xl bg-emerald-700 px-5 py-3 text-sm font-bold text-white hover:bg-emerald-800">Browse tours</a>
                    </div>
                @else
                    <div class="divide-y divide-slate-200">
                        @foreach ($bookings as $booking)
                            @php
                                $statusValue = $booking->status?->value ?? $booking->status;
                                $statusLabel = $booking->status instanceof \App\Enums\TourBookingStatus ? $booking->status->label() : str($statusValue)->headline();
                            @endphp
                            <article class="grid gap-5 px-5 py-6 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-center lg:px-6" aria-labelledby="booking-{{ $booking->id }}">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <h3 id="booking-{{ $booking->id }}" class="font-bold text-slate-950">{{ $booking->package_name_snapshot }}</h3>
                                        <span @class([
                                            'rounded-full px-2.5 py-1 text-xs font-bold',
                                            'bg-amber-50 text-amber-800' => $statusValue === 'pending',
                                            'bg-emerald-50 text-emerald-800' => $statusValue === 'confirmed',
                                            'bg-sky-50 text-sky-800' => in_array($statusValue, ['in_progress', 'completed'], true),
                                            'bg-rose-50 text-rose-800' => $statusValue === 'cancelled',
                                        ])>{{ $statusLabel }}</span>
                                    </div>
                                    <p class="mt-1 font-mono text-xs font-semibold text-emerald-700">{{ $booking->reference }}</p>
                                    <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2 xl:grid-cols-4">
                                        <div><dt class="text-xs text-slate-500">Departure</dt><dd class="mt-0.5 font-semibold text-slate-800"><time datetime="{{ $booking->departure_starts_at_snapshot->toDateString() }}">{{ $booking->departure_starts_at_snapshot->timezone(config('pisfa.business_timezone'))->format('j M Y') }}</time></dd></div>
                                        <div><dt class="text-xs text-slate-500">Travelers</dt><dd class="mt-0.5 font-semibold text-slate-800">{{ $booking->traveler_count }}</dd></div>
                                        <div><dt class="text-xs text-slate-500">Total</dt><dd class="mt-0.5 font-semibold text-slate-800">{{ \App\Support\Money::format((int) $booking->total_minor, $booking->currency) }}</dd></div>
                                        <div><dt class="text-xs text-slate-500">Requested</dt><dd class="mt-0.5 font-semibold text-slate-800"><time datetime="{{ $booking->created_at->toIso8601String() }}">{{ $booking->created_at->timezone(config('pisfa.business_timezone'))->format('j M Y') }}</time></dd></div>
                                    </dl>
                                </div>
                                <a href="{{ route('portal.bookings.show', ['customerTourBooking' => $booking]) }}" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-emerald-200 px-4 py-2.5 text-sm font-bold text-emerald-800 hover:bg-emerald-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600">View details</a>
                            </article>
                        @endforeach
                    </div>
                @endif
            </section>

            {{ $bookings->links() }}
        </div>
    </div>
</x-app-layout>
