<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between"><div><p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">Tours &amp; safaris</p><h1 class="mt-1 text-2xl font-bold tracking-tight text-slate-950">Tour bookings</h1></div><a href="{{ route('admin.tours.index') }}" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50">Manage packages</a></div>
    </x-slot>

    <div class="py-8 sm:py-10">
        <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
            @if (session('success') || session('status'))<div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-950" role="status" aria-live="polite">{{ session('success') ?? session('status') }}</div>@endif
            @if ($errors->any())<div class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800" role="alert"><p class="font-bold">The booking list could not be filtered.</p><ul class="mt-2 list-disc space-y-1 pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

            <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6" aria-labelledby="admin-booking-filters-heading">
                <h2 id="admin-booking-filters-heading" class="sr-only">Filter tour bookings</h2>
                <form method="GET" action="{{ route('admin.tour-bookings.index') }}" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4 xl:items-end">
                    <div><label for="booking-search" class="block text-sm font-semibold text-slate-800">Search</label><input id="booking-search" name="q" type="search" maxlength="100" value="{{ $filters['q'] ?? '' }}" placeholder="Reference, customer or phone" class="mt-1 block w-full rounded-xl border-slate-300 shadow-sm focus:border-emerald-600 focus:ring-emerald-600"></div>
                    <div><label for="booking-package" class="block text-sm font-semibold text-slate-800">Package</label><select id="booking-package" name="package_id" class="mt-1 block w-full rounded-xl border-slate-300 shadow-sm focus:border-emerald-600 focus:ring-emerald-600"><option value="">All packages</option>@foreach ($packages as $packageOption)<option value="{{ $packageOption->id }}" @selected((string) ($filters['package_id'] ?? '') === (string) $packageOption->id)>{{ $packageOption->name }}</option>@endforeach</select></div>
                    <div><label for="booking-status" class="block text-sm font-semibold text-slate-800">Status</label><select id="booking-status" name="status" class="mt-1 block w-full rounded-xl border-slate-300 shadow-sm focus:border-emerald-600 focus:ring-emerald-600"><option value="">All statuses</option>@foreach (\App\Enums\TourBookingStatus::cases() as $status)<option value="{{ $status->value }}" @selected(($filters['status'] ?? '') === $status->value)>{{ $status->label() }}</option>@endforeach</select></div>
                    <div><label for="booking-period" class="block text-sm font-semibold text-slate-800">Travel period</label><select id="booking-period" name="period" class="mt-1 block w-full rounded-xl border-slate-300 shadow-sm focus:border-emerald-600 focus:ring-emerald-600"><option value="">All dates</option><option value="upcoming" @selected(($filters['period'] ?? '') === 'upcoming')>Upcoming</option><option value="past" @selected(($filters['period'] ?? '') === 'past')>Past</option></select></div>
                    <div><label for="booking-assignment" class="block text-sm font-semibold text-slate-800">Driver</label><select id="booking-assignment" name="assignment" class="mt-1 block w-full rounded-xl border-slate-300 shadow-sm focus:border-emerald-600 focus:ring-emerald-600"><option value="">Any assignment</option><option value="assigned" @selected(($filters['assignment'] ?? '') === 'assigned')>Assigned</option><option value="unassigned" @selected(($filters['assignment'] ?? '') === 'unassigned')>Unassigned</option></select></div>
                    <div><label for="booking-from" class="block text-sm font-semibold text-slate-800">From</label><input id="booking-from" name="from" type="date" value="{{ $filters['from'] ?? '' }}" class="mt-1 block w-full rounded-xl border-slate-300 shadow-sm focus:border-emerald-600 focus:ring-emerald-600"></div>
                    <div><label for="booking-to" class="block text-sm font-semibold text-slate-800">To</label><input id="booking-to" name="to" type="date" value="{{ $filters['to'] ?? '' }}" class="mt-1 block w-full rounded-xl border-slate-300 shadow-sm focus:border-emerald-600 focus:ring-emerald-600"></div>
                    <div class="flex gap-2"><button type="submit" class="inline-flex min-h-11 items-center rounded-xl bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-emerald-800">Filter</button><a href="{{ route('admin.tour-bookings.index') }}" class="inline-flex min-h-11 items-center rounded-xl px-3 py-2.5 text-sm font-bold text-slate-600 hover:bg-slate-100">Reset</a></div>
                </form>
            </section>

            <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm" aria-labelledby="admin-booking-list-heading">
                <div class="border-b border-slate-200 px-5 py-5 sm:px-6"><h2 id="admin-booking-list-heading" class="text-xl font-black text-slate-950">Booking requests</h2><p class="mt-1 text-sm text-slate-500">{{ $bookings->total() }} {{ str('booking')->plural($bookings->total()) }}</p></div>
                @if ($bookings->isEmpty())
                    <div class="px-6 py-16 text-center"><h3 class="font-bold text-slate-900">No bookings match these filters</h3><p class="mt-2 text-sm text-slate-600">Reset the filters or wait for a customer request.</p></div>
                @else
                    <div class="divide-y divide-slate-200 md:hidden">
                        @foreach ($bookings as $booking)
                            @php
                                $statusValue = $booking->status->value;
                            @endphp
                            <article class="p-5" aria-labelledby="mobile-booking-{{ $booking->id }}"><div class="flex items-start justify-between gap-3"><div><h3 id="mobile-booking-{{ $booking->id }}" class="font-black text-slate-950">{{ $booking->package_name_snapshot }}</h3><p class="mt-1 font-mono text-xs font-semibold text-emerald-700">{{ $booking->reference }}</p></div><span @class(['rounded-full px-2.5 py-1 text-xs font-bold', 'bg-amber-50 text-amber-800' => $statusValue === 'pending', 'bg-emerald-50 text-emerald-800' => $statusValue === 'confirmed', 'bg-sky-50 text-sky-800' => in_array($statusValue, ['in_progress','completed'], true), 'bg-rose-50 text-rose-800' => $statusValue === 'cancelled'])>{{ $booking->status->label() }}</span></div><dl class="mt-4 grid grid-cols-2 gap-3 text-sm"><div><dt class="text-xs text-slate-500">Customer</dt><dd class="mt-0.5 font-semibold">{{ $booking->contact_name }}</dd></div><div><dt class="text-xs text-slate-500">Departure</dt><dd class="mt-0.5 font-semibold">{{ $booking->departure_starts_at_snapshot->timezone(config('pisfa.business_timezone'))->format('j M Y') }}</dd></div><div><dt class="text-xs text-slate-500">Travelers</dt><dd class="mt-0.5 font-semibold">{{ $booking->traveler_count }}</dd></div><div><dt class="text-xs text-slate-500">Driver</dt><dd class="mt-0.5 font-semibold">{{ $booking->assignedDriver?->name ?? 'Unassigned' }}</dd></div></dl><a href="{{ route('admin.tour-bookings.show', ['tourBooking' => $booking]) }}" class="mt-4 inline-flex min-h-11 w-full items-center justify-center rounded-xl border border-emerald-200 px-4 py-2.5 text-sm font-bold text-emerald-800 hover:bg-emerald-50">Open booking</a></article>
                        @endforeach
                    </div>
                    <div class="hidden overflow-x-auto md:block">
                        <table class="min-w-full divide-y divide-slate-200 text-left text-sm">
                            <caption class="sr-only">Filtered tour booking requests</caption>
                            <thead class="bg-slate-50 text-xs font-bold uppercase tracking-wide text-slate-500"><tr><th scope="col" class="px-5 py-3">Reference</th><th scope="col" class="px-5 py-3">Customer</th><th scope="col" class="px-5 py-3">Tour and date</th><th scope="col" class="px-5 py-3">Travelers</th><th scope="col" class="px-5 py-3">Total</th><th scope="col" class="px-5 py-3">Driver</th><th scope="col" class="px-5 py-3">Status</th><th scope="col" class="px-5 py-3"><span class="sr-only">Actions</span></th></tr></thead>
                            <tbody class="divide-y divide-slate-200 bg-white">
                                @foreach ($bookings as $booking)
                                    @php
                                        $statusValue = $booking->status->value;
                                    @endphp
                                    <tr><td class="whitespace-nowrap px-5 py-4 font-mono text-xs font-semibold text-emerald-700">{{ $booking->reference }}</td><td class="px-5 py-4"><p class="font-semibold text-slate-900">{{ $booking->contact_name }}</p><p class="mt-0.5 text-xs text-slate-500">{{ $booking->contact_phone }}</p></td><td class="px-5 py-4"><p class="max-w-52 truncate font-semibold text-slate-900">{{ $booking->package_name_snapshot }}</p><time datetime="{{ $booking->departure_starts_at_snapshot->toDateString() }}" class="mt-0.5 block text-xs text-slate-500">{{ $booking->departure_starts_at_snapshot->timezone(config('pisfa.business_timezone'))->format('j M Y') }}</time></td><td class="px-5 py-4 font-semibold">{{ $booking->traveler_count }}</td><td class="whitespace-nowrap px-5 py-4 font-semibold">{{ \App\Support\Money::format((int) $booking->total_minor, $booking->currency) }}</td><td class="px-5 py-4 text-slate-700">{{ $booking->assignedDriver?->name ?? 'Unassigned' }}</td><td class="px-5 py-4"><span @class(['rounded-full px-2.5 py-1 text-xs font-bold', 'bg-amber-50 text-amber-800' => $statusValue === 'pending', 'bg-emerald-50 text-emerald-800' => $statusValue === 'confirmed', 'bg-sky-50 text-sky-800' => in_array($statusValue, ['in_progress','completed'], true), 'bg-rose-50 text-rose-800' => $statusValue === 'cancelled'])>{{ $booking->status->label() }}</span></td><td class="px-5 py-4"><a href="{{ route('admin.tour-bookings.show', ['tourBooking' => $booking]) }}" class="font-bold text-emerald-800 underline decoration-emerald-300 underline-offset-4">Open</a></td></tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
            {{ $bookings->links() }}
        </div>
    </div>
</x-app-layout>
