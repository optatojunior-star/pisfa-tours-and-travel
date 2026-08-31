@php
    use App\Enums\PropertyBookingStatus;
@endphp
<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">Accommodation</p>
                <h1 class="mt-1 text-2xl font-bold text-slate-950">Stays</h1>
            </div>
            <a href="{{ route('admin.accommodation.index') }}" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50">
                Properties
            </a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
            @if (session('success'))
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-900" role="status">{{ session('success') }}</div>
            @endif

            <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4" aria-label="Stay counts">
                @foreach ([PropertyBookingStatus::Pending, PropertyBookingStatus::Confirmed, PropertyBookingStatus::CheckedIn, PropertyBookingStatus::CheckedOut] as $case)
                    <a href="{{ route('admin.accommodation.bookings.index', ['status' => $case->value]) }}" @class([
                        'rounded-2xl border p-5 transition focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600',
                        'border-slate-200 bg-white hover:bg-slate-50',
                        'ring-2 ring-emerald-600' => $status === $case,
                    ])>
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $case->label() }}</p>
                        <p class="mt-1 text-2xl font-black text-slate-900">{{ $counts[$case->value] ?? 0 }}</p>
                    </a>
                @endforeach
            </section>

            @if (($counts['arriving_today'] ?? 0) > 0)
                <p class="rounded-xl border border-sky-200 bg-sky-50 p-4 text-sm font-semibold text-sky-900">
                    {{ $counts['arriving_today'] }} {{ Str::plural('guest', $counts['arriving_today']) }}
                    {{ $counts['arriving_today'] === 1 ? 'arrives' : 'arrive' }} today.
                </p>
            @endif

            <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm" aria-labelledby="stay-filters">
                <h2 id="stay-filters" class="sr-only">Filter stays</h2>
                <form method="GET" action="{{ route('admin.accommodation.bookings.index') }}" class="grid gap-4 sm:grid-cols-3 sm:items-end">
                    <div>
                        <label for="stay-q" class="block text-sm font-semibold">Search</label>
                        <input id="stay-q" name="q" type="search" maxlength="100" value="{{ $search }}"
                               placeholder="Reference, guest, or property"
                               class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                    </div>
                    <div>
                        <label for="stay-property" class="block text-sm font-semibold">Property</label>
                        <select id="stay-property" name="property" class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                            <option value="">All properties</option>
                            @foreach ($properties as $option)
                                <option value="{{ $option->slug }}" @selected($propertySlug === $option->slug)>{{ $option->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="stay-status" class="block text-sm font-semibold">Status</label>
                        <select id="stay-status" name="status" class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                            <option value="">Live stays</option>
                            @foreach (PropertyBookingStatus::cases() as $case)
                                <option value="{{ $case->value }}" @selected($status === $case)>{{ $case->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <label class="flex items-center gap-2 text-sm font-semibold sm:col-span-3">
                        <input type="checkbox" name="show" value="all" @checked($showAll)
                               class="rounded border-slate-300 text-emerald-700 focus:ring-emerald-600">
                        Include finished and cancelled
                    </label>
                    <div class="flex gap-2 sm:col-span-3">
                        <button type="submit" class="inline-flex min-h-11 items-center rounded-xl bg-emerald-700 px-5 text-sm font-bold text-white hover:bg-emerald-800">Apply</button>
                        <a href="{{ route('admin.accommodation.bookings.index') }}" class="inline-flex min-h-11 items-center rounded-xl border border-slate-300 px-5 text-sm font-bold text-slate-700">Reset</a>
                    </div>
                </form>
            </section>

            @if ($bookings->isEmpty())
                <div class="rounded-3xl border border-dashed border-slate-300 bg-white p-12 text-center">
                    <h2 class="text-lg font-bold text-slate-900">Nothing here</h2>
                    <p class="mt-2 text-sm text-slate-600">
                        No stays match this filter. Finished and cancelled ones are hidden unless you ask for them.
                    </p>
                </div>
            @else
                <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <caption class="sr-only">Accommodation stays</caption>
                            <thead class="bg-slate-50">
                                <tr>
                                    <th scope="col" class="px-5 py-3 text-left font-bold text-slate-700">Guest</th>
                                    <th scope="col" class="px-5 py-3 text-left font-bold text-slate-700">Property</th>
                                    <th scope="col" class="px-5 py-3 text-left font-bold text-slate-700">Dates</th>
                                    <th scope="col" class="px-5 py-3 text-left font-bold text-slate-700">Rooms</th>
                                    <th scope="col" class="px-5 py-3 text-left font-bold text-slate-700">Status</th>
                                    <th scope="col" class="px-5 py-3 text-left font-bold text-slate-700">Total</th>
                                    <th scope="col" class="px-5 py-3 text-right font-bold text-slate-700">Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ($bookings as $booking)
                                    <tr class="hover:bg-slate-50">
                                        <td class="px-5 py-4">
                                            <p class="font-bold text-slate-900">{{ $booking->contact_name }}</p>
                                            <p class="text-xs text-slate-500">{{ $booking->reference }}</p>
                                        </td>
                                        <td class="px-5 py-4 text-slate-700">
                                            {{ $booking->property_name_snapshot }}
                                            <span class="block text-xs text-slate-500">{{ $booking->room_type_name_snapshot }}</span>
                                        </td>
                                        <td class="px-5 py-4 text-slate-700">
                                            {{ $booking->check_in_date->format('j M') }} — {{ $booking->check_out_date->format('j M Y') }}
                                            <span class="block text-xs text-slate-500">{{ $booking->nights }} {{ Str::plural('night', $booking->nights) }}</span>
                                        </td>
                                        <td class="px-5 py-4 text-slate-700">{{ $booking->rooms }}</td>
                                        <td class="px-5 py-4">
                                            <span @class([
                                                'inline-flex rounded-full px-2.5 py-1 text-xs font-bold',
                                                'bg-amber-100 text-amber-900' => $booking->status->tone() === 'amber',
                                                'bg-sky-100 text-sky-900' => $booking->status->tone() === 'sky',
                                                'bg-emerald-100 text-emerald-900' => $booking->status->tone() === 'emerald',
                                                'bg-slate-100 text-slate-800' => $booking->status->tone() === 'slate',
                                                'bg-rose-100 text-rose-900' => $booking->status->tone() === 'rose',
                                            ])>{{ $booking->status->label() }}</span>
                                        </td>
                                        <td class="px-5 py-4 font-semibold text-slate-900">{{ $booking->formattedTotal() }}</td>
                                        <td class="px-5 py-4 text-right">
                                            <a href="{{ route('admin.accommodation.bookings.show', $booking) }}" class="font-bold text-emerald-800 hover:underline">Open</a>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
                <div>{{ $bookings->links() }}</div>
            @endif
        </div>
    </div>
</x-app-layout>
