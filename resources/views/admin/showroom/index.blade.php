@php
    use App\Enums\ListingStatus;
@endphp
<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">Showroom</p>
                <h1 class="mt-1 text-2xl font-bold text-slate-950">Vehicles for sale</h1>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('admin.showroom.enquiries.index') }}" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50">
                    Enquiries
                    @if (($counts['open_enquiries'] ?? 0) > 0)
                        <span class="ml-2 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-black text-amber-900">{{ $counts['open_enquiries'] }}</span>
                    @endif
                </a>
                <a href="{{ route('admin.showroom.create') }}" class="inline-flex min-h-11 items-center justify-center rounded-xl bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-emerald-800">New listing</a>
            </div>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
            @if (session('success'))
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-900" role="status">{{ session('success') }}</div>
            @endif

            <section class="grid gap-4 sm:grid-cols-3 lg:grid-cols-5" aria-label="Listing counts">
                @foreach (ListingStatus::cases() as $case)
                    <a href="{{ route('admin.showroom.index', ['status' => $case->value]) }}" @class([
                        'rounded-2xl border p-5 transition focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600',
                        'border-slate-200 bg-white hover:bg-slate-50',
                        'ring-2 ring-emerald-600' => $status === $case,
                    ])>
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $case->label() }}</p>
                        <p class="mt-1 text-2xl font-black text-slate-900">{{ $counts[$case->value] ?? 0 }}</p>
                    </a>
                @endforeach
            </section>

            <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm" aria-labelledby="listing-filters">
                <h2 id="listing-filters" class="sr-only">Filter listings</h2>
                <form method="GET" action="{{ route('admin.showroom.index') }}" class="grid gap-4 sm:grid-cols-3 sm:items-end">
                    <div class="sm:col-span-2">
                        <label for="listing-q" class="block text-sm font-semibold">Search</label>
                        <input id="listing-q" name="q" type="search" maxlength="100" value="{{ $search }}"
                               placeholder="Title, make, model, or reference"
                               class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                    </div>
                    <div>
                        <label for="listing-status" class="block text-sm font-semibold">Status</label>
                        <select id="listing-status" name="status" class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                            <option value="">All statuses</option>
                            @foreach (ListingStatus::cases() as $case)
                                <option value="{{ $case->value }}" @selected($status === $case)>{{ $case->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="flex gap-2 sm:col-span-3">
                        <button type="submit" class="inline-flex min-h-11 items-center rounded-xl bg-emerald-700 px-5 text-sm font-bold text-white hover:bg-emerald-800">Apply</button>
                        <a href="{{ route('admin.showroom.index') }}" class="inline-flex min-h-11 items-center rounded-xl border border-slate-300 px-5 text-sm font-bold text-slate-700">Reset</a>
                    </div>
                </form>
            </section>

            @if ($listings->isEmpty())
                <div class="rounded-3xl border border-dashed border-slate-300 bg-white p-12 text-center">
                    <h2 class="text-lg font-bold text-slate-900">No listings here</h2>
                    <p class="mt-2 text-sm text-slate-600">
                        Nothing matches this filter.
                        <a href="{{ route('admin.showroom.create') }}" class="font-bold text-emerald-800 underline">Create a listing</a>
                        to put a vehicle in the showroom.
                    </p>
                </div>
            @else
                <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <caption class="sr-only">Showroom listings</caption>
                            <thead class="bg-slate-50">
                                <tr>
                                    <th scope="col" class="px-5 py-3 text-left font-bold text-slate-700">Vehicle</th>
                                    <th scope="col" class="px-5 py-3 text-left font-bold text-slate-700">Status</th>
                                    <th scope="col" class="px-5 py-3 text-left font-bold text-slate-700">Asking</th>
                                    <th scope="col" class="px-5 py-3 text-left font-bold text-slate-700">Days listed</th>
                                    <th scope="col" class="px-5 py-3 text-left font-bold text-slate-700">Open enquiries</th>
                                    <th scope="col" class="px-5 py-3 text-right font-bold text-slate-700">Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ($listings as $listing)
                                    <tr class="hover:bg-slate-50">
                                        <td class="px-5 py-4">
                                            <p class="font-bold text-slate-900">{{ $listing->title }}</p>
                                            <p class="text-xs text-slate-500">
                                                {{ $listing->reference }}
                                                @if ($listing->vehicle)
                                                    · fleet {{ $listing->vehicle->registration_plate }}
                                                @endif
                                            </p>
                                        </td>
                                        <td class="px-5 py-4">
                                            <span @class([
                                                'inline-flex rounded-full px-2.5 py-1 text-xs font-bold',
                                                'bg-slate-100 text-slate-800' => $listing->status->tone() === 'slate',
                                                'bg-emerald-100 text-emerald-900' => $listing->status->tone() === 'emerald',
                                                'bg-amber-100 text-amber-900' => $listing->status->tone() === 'amber',
                                                'bg-sky-100 text-sky-900' => $listing->status->tone() === 'sky',
                                                'bg-rose-100 text-rose-900' => $listing->status->tone() === 'rose',
                                            ])>{{ $listing->status->label() }}</span>
                                        </td>
                                        <td class="px-5 py-4 font-semibold text-slate-900">
                                            {{ $listing->formattedAskingPrice() }}
                                            @if ($listing->formattedSoldPrice())
                                                <span class="block text-xs font-normal text-sky-800">sold {{ $listing->formattedSoldPrice() }}</span>
                                            @endif
                                        </td>
                                        <td class="px-5 py-4 text-slate-700">{{ $listing->daysListed() ?? '—' }}</td>
                                        <td class="px-5 py-4 text-slate-700">{{ $listing->open_enquiries_count }}</td>
                                        <td class="px-5 py-4 text-right">
                                            <a href="{{ route('admin.showroom.show', $listing) }}" class="font-bold text-emerald-800 hover:underline">Open</a>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
                <div>{{ $listings->links() }}</div>
            @endif
        </div>
    </div>
</x-app-layout>
