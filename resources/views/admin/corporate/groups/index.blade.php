@php
    use App\Enums\GroupBookingStatus;
@endphp
<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">Corporate</p>
                <h1 class="mt-1 text-2xl font-bold text-slate-950">Group bookings</h1>
            </div>
            <a href="{{ route('admin.corporate.index') }}" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50">
                Company accounts
            </a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
            @if (session('success'))
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-900" role="status">{{ session('success') }}</div>
            @endif

            <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4" aria-label="Group counts">
                @foreach ([GroupBookingStatus::Enquiry, GroupBookingStatus::Quoted, GroupBookingStatus::ManifestPending, GroupBookingStatus::Confirmed] as $case)
                    <a href="{{ route('admin.corporate.groups.index', ['status' => $case->value]) }}" @class([
                        'rounded-2xl border p-5 transition focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600',
                        'border-slate-200 bg-white hover:bg-slate-50',
                        'ring-2 ring-emerald-600' => $status === $case,
                    ])>
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $case->label() }}</p>
                        <p class="mt-1 text-2xl font-black text-slate-900">{{ $counts[$case->value] ?? 0 }}</p>
                    </a>
                @endforeach
            </section>

            <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm" aria-labelledby="group-filters">
                <h2 id="group-filters" class="sr-only">Filter groups</h2>
                <form method="GET" action="{{ route('admin.corporate.groups.index') }}" class="grid gap-4 sm:grid-cols-3 sm:items-end">
                    <div class="sm:col-span-2">
                        <label for="group-q" class="block text-sm font-semibold">Search</label>
                        <input id="group-q" name="q" type="search" maxlength="100" value="{{ $search }}"
                               placeholder="Reference, title, or destination"
                               class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                    </div>
                    <div>
                        <label for="group-status" class="block text-sm font-semibold">Status</label>
                        <select id="group-status" name="status" class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                            <option value="">Open groups</option>
                            @foreach (GroupBookingStatus::cases() as $case)
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
                        <a href="{{ route('admin.corporate.groups.index') }}" class="inline-flex min-h-11 items-center rounded-xl border border-slate-300 px-5 text-sm font-bold text-slate-700">Reset</a>
                    </div>
                </form>
            </section>

            @if ($bookings->isEmpty())
                <div class="rounded-3xl border border-dashed border-slate-300 bg-white p-12 text-center">
                    <h2 class="text-lg font-bold text-slate-900">Nothing here</h2>
                    <p class="mt-2 text-sm text-slate-600">No groups match this filter.</p>
                </div>
            @else
                <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <caption class="sr-only">Group bookings</caption>
                            <thead class="bg-slate-50">
                                <tr>
                                    <th scope="col" class="px-5 py-3 text-left font-bold text-slate-700">Trip</th>
                                    <th scope="col" class="px-5 py-3 text-left font-bold text-slate-700">Organiser</th>
                                    <th scope="col" class="px-5 py-3 text-left font-bold text-slate-700">Dates</th>
                                    <th scope="col" class="px-5 py-3 text-left font-bold text-slate-700">List</th>
                                    <th scope="col" class="px-5 py-3 text-left font-bold text-slate-700">Price</th>
                                    <th scope="col" class="px-5 py-3 text-left font-bold text-slate-700">Status</th>
                                    <th scope="col" class="px-5 py-3 text-right font-bold text-slate-700">Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ($bookings as $booking)
                                    <tr class="hover:bg-slate-50">
                                        <td class="px-5 py-4">
                                            <p class="font-bold text-slate-900">{{ $booking->title }}</p>
                                            <p class="text-xs text-slate-500">
                                                {{ $booking->reference }}
                                                @if ($booking->account)
                                                    · {{ $booking->account->name }}
                                                @endif
                                            </p>
                                        </td>
                                        <td class="px-5 py-4 text-slate-700">{{ $booking->organiser?->name ?? '—' }}</td>
                                        <td class="px-5 py-4 text-slate-700">{{ $booking->dateLabel() }}</td>
                                        <td class="px-5 py-4">
                                            <span @class([
                                                'font-semibold',
                                                'text-emerald-800' => $booking->travelers_count === $booking->headcount,
                                                'text-amber-800' => $booking->travelers_count < $booking->headcount,
                                            ])>{{ $booking->travelers_count }} of {{ $booking->headcount }}</span>
                                        </td>
                                        <td class="px-5 py-4 text-slate-700">
                                            {{ $booking->formattedQuotedTotal() ?? '—' }}
                                        </td>
                                        <td class="px-5 py-4">
                                            <span @class([
                                                'inline-flex rounded-full px-2.5 py-1 text-xs font-bold',
                                                'bg-slate-100 text-slate-800' => $booking->status->tone() === 'slate',
                                                'bg-amber-100 text-amber-900' => $booking->status->tone() === 'amber',
                                                'bg-sky-100 text-sky-900' => $booking->status->tone() === 'sky',
                                                'bg-emerald-100 text-emerald-900' => $booking->status->tone() === 'emerald',
                                                'bg-rose-100 text-rose-900' => $booking->status->tone() === 'rose',
                                            ])>{{ $booking->status->label() }}</span>
                                        </td>
                                        <td class="px-5 py-4 text-right">
                                            <a href="{{ route('admin.corporate.groups.show', $booking) }}" class="font-bold text-emerald-800 hover:underline">Open</a>
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
