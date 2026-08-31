@php
    use App\Enums\SalesEnquiryStatus;

    $timezone = config('pisfa.business_timezone', 'Africa/Kampala');
@endphp
<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">Showroom</p>
                <h1 class="mt-1 text-2xl font-bold text-slate-950">Sales enquiries</h1>
            </div>
            <a href="{{ route('admin.showroom.index') }}" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50">
                Listings
            </a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
            @if (session('success'))
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-900" role="status">{{ session('success') }}</div>
            @endif

            <section class="grid gap-4 sm:grid-cols-3 lg:grid-cols-4" aria-label="Enquiry counts">
                @foreach ([SalesEnquiryStatus::New, SalesEnquiryStatus::Contacted, SalesEnquiryStatus::Negotiating, SalesEnquiryStatus::Won] as $case)
                    <a href="{{ route('admin.showroom.enquiries.index', ['status' => $case->value]) }}" @class([
                        'rounded-2xl border p-5 transition focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600',
                        'border-slate-200 bg-white hover:bg-slate-50',
                        'ring-2 ring-emerald-600' => $status === $case,
                    ])>
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $case->label() }}</p>
                        <p class="mt-1 text-2xl font-black text-slate-900">{{ $counts[$case->value] ?? 0 }}</p>
                    </a>
                @endforeach
            </section>

            @if (($counts['unassigned'] ?? 0) > 0)
                <p class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm font-semibold text-amber-900">
                    {{ $counts['unassigned'] }} open {{ Str::plural('enquiry', $counts['unassigned']) }}
                    {{ $counts['unassigned'] === 1 ? 'has' : 'have' }} nobody assigned to
                    {{ $counts['unassigned'] === 1 ? 'it' : 'them' }}.
                </p>
            @endif

            <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm" aria-labelledby="enquiry-filters">
                <h2 id="enquiry-filters" class="sr-only">Filter enquiries</h2>
                <form method="GET" action="{{ route('admin.showroom.enquiries.index') }}" class="grid gap-4 sm:grid-cols-3 sm:items-end">
                    <div class="sm:col-span-2">
                        <label for="enquiry-q" class="block text-sm font-semibold">Search</label>
                        <input id="enquiry-q" name="q" type="search" maxlength="100" value="{{ $search }}"
                               placeholder="Name, email, or reference"
                               class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                    </div>
                    <div>
                        <label for="enquiry-status" class="block text-sm font-semibold">Status</label>
                        <select id="enquiry-status" name="status" class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                            <option value="">Open enquiries</option>
                            @foreach (SalesEnquiryStatus::cases() as $case)
                                <option value="{{ $case->value }}" @selected($status === $case)>{{ $case->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="flex flex-wrap items-center gap-4 sm:col-span-3">
                        <label class="flex items-center gap-2 text-sm font-semibold">
                            <input type="checkbox" name="mine" value="1" @checked($mine)
                                   class="rounded border-slate-300 text-emerald-700 focus:ring-emerald-600">
                            Only mine
                        </label>
                        <label class="flex items-center gap-2 text-sm font-semibold">
                            <input type="checkbox" name="show" value="all" @checked($showAll)
                                   class="rounded border-slate-300 text-emerald-700 focus:ring-emerald-600">
                            Include closed
                        </label>
                    </div>
                    <div class="flex gap-2 sm:col-span-3">
                        <button type="submit" class="inline-flex min-h-11 items-center rounded-xl bg-emerald-700 px-5 text-sm font-bold text-white hover:bg-emerald-800">Apply</button>
                        <a href="{{ route('admin.showroom.enquiries.index') }}" class="inline-flex min-h-11 items-center rounded-xl border border-slate-300 px-5 text-sm font-bold text-slate-700">Reset</a>
                    </div>
                </form>
            </section>

            @if ($enquiries->isEmpty())
                <div class="rounded-3xl border border-dashed border-slate-300 bg-white p-12 text-center">
                    <h2 class="text-lg font-bold text-slate-900">Nothing here</h2>
                    <p class="mt-2 text-sm text-slate-600">
                        No enquiries match this filter. Closed ones are hidden unless you ask for them.
                    </p>
                </div>
            @else
                <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <caption class="sr-only">Sales enquiries</caption>
                            <thead class="bg-slate-50">
                                <tr>
                                    <th scope="col" class="px-5 py-3 text-left font-bold text-slate-700">Enquirer</th>
                                    <th scope="col" class="px-5 py-3 text-left font-bold text-slate-700">Vehicle</th>
                                    <th scope="col" class="px-5 py-3 text-left font-bold text-slate-700">Status</th>
                                    <th scope="col" class="px-5 py-3 text-left font-bold text-slate-700">Offer</th>
                                    <th scope="col" class="px-5 py-3 text-left font-bold text-slate-700">Assigned</th>
                                    <th scope="col" class="px-5 py-3 text-right font-bold text-slate-700">Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ($enquiries as $enquiry)
                                    <tr class="hover:bg-slate-50">
                                        <td class="px-5 py-4">
                                            <p class="font-bold text-slate-900">{{ $enquiry->contact_name }}</p>
                                            <p class="text-xs text-slate-500">
                                                {{ $enquiry->reference }} ·
                                                {{ $enquiry->created_at->timezone($timezone)->format('j M, H:i') }}
                                                @if ($enquiry->isGuest())
                                                    · guest
                                                @endif
                                            </p>
                                        </td>
                                        <td class="px-5 py-4 text-slate-700">
                                            @if ($enquiry->listing)
                                                <a href="{{ route('admin.showroom.show', $enquiry->listing) }}" class="font-semibold text-emerald-800 hover:underline">
                                                    {{ $enquiry->listing->title }}
                                                </a>
                                            @else
                                                <span class="text-slate-400">Listing removed</span>
                                            @endif
                                        </td>
                                        <td class="px-5 py-4">
                                            <span @class([
                                                'inline-flex rounded-full px-2.5 py-1 text-xs font-bold',
                                                'bg-amber-100 text-amber-900' => $enquiry->status->tone() === 'amber',
                                                'bg-sky-100 text-sky-900' => $enquiry->status->tone() === 'sky',
                                                'bg-emerald-100 text-emerald-900' => $enquiry->status->tone() === 'emerald',
                                                'bg-rose-100 text-rose-900' => $enquiry->status->tone() === 'rose',
                                            ])>{{ $enquiry->status->label() }}</span>
                                        </td>
                                        <td class="px-5 py-4 text-slate-700">{{ $enquiry->formattedOffer() ?? '—' }}</td>
                                        <td class="px-5 py-4 text-slate-700">{{ $enquiry->assignee?->name ?? 'Nobody' }}</td>
                                        <td class="px-5 py-4 text-right">
                                            <a href="{{ route('admin.showroom.enquiries.show', $enquiry) }}" class="font-bold text-emerald-800 hover:underline">Open</a>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
                <div>{{ $enquiries->links() }}</div>
            @endif
        </div>
    </div>
</x-app-layout>
