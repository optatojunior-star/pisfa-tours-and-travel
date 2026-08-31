@php
    use App\Enums\QuotationRequestStatus;
    use App\Support\ServiceCatalogue;

    $timezone = config('pisfa.business_timezone', 'Africa/Kampala');
@endphp
<x-app-layout>
    <x-slot name="header">
        <div>
            <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">Billing</p>
            <h1 class="mt-1 text-2xl font-bold text-slate-950">Quotation requests</h1>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
            @if (session('success'))
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-900" role="status">{{ session('success') }}</div>
            @endif

            <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4" aria-label="Request counts">
                <a href="{{ route('admin.quotation-requests.index', ['status' => QuotationRequestStatus::New->value]) }}" @class([
                    'rounded-2xl border p-5 transition focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600',
                    'border-amber-300 bg-amber-50 hover:bg-amber-100' => $counts['new'] > 0,
                    'border-slate-200 bg-white hover:bg-slate-50' => $counts['new'] === 0,
                    'ring-2 ring-emerald-600' => $status === QuotationRequestStatus::New,
                ])>
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">New</p>
                    <p class="mt-1 text-2xl font-black text-slate-900">{{ $counts['new'] }}</p>
                    <p class="mt-1 text-xs text-slate-500">Nobody has picked these up</p>
                </a>
                <a href="{{ route('admin.quotation-requests.index', ['status' => QuotationRequestStatus::InReview->value]) }}" @class([
                    'rounded-2xl border border-slate-200 bg-white p-5 transition hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600',
                    'ring-2 ring-emerald-600' => $status === QuotationRequestStatus::InReview,
                ])>
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">In review</p>
                    <p class="mt-1 text-2xl font-black text-slate-900">{{ $counts['in_review'] }}</p>
                </a>
                <a href="{{ route('admin.quotation-requests.index', ['status' => QuotationRequestStatus::Quoted->value]) }}" @class([
                    'rounded-2xl border border-slate-200 bg-white p-5 transition hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600',
                    'ring-2 ring-emerald-600' => $status === QuotationRequestStatus::Quoted,
                ])>
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Quoted</p>
                    <p class="mt-1 text-2xl font-black text-slate-900">{{ $counts['quoted'] }}</p>
                </a>
                <div class="rounded-2xl border border-slate-200 bg-white p-5">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Open in total</p>
                    <p class="mt-1 text-2xl font-black text-slate-900">{{ $counts['open'] }}</p>
                </div>
            </section>

            <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm" aria-labelledby="request-filters">
                <h2 id="request-filters" class="sr-only">Filter requests</h2>
                <form method="GET" action="{{ route('admin.quotation-requests.index') }}" class="grid gap-4 sm:grid-cols-4 sm:items-end">
                    <div class="sm:col-span-2">
                        <label for="req-q" class="block text-sm font-semibold">Search</label>
                        <input id="req-q" name="q" type="search" maxlength="100" value="{{ $search }}"
                               placeholder="Reference, name, email, organisation"
                               class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                    </div>
                    <div>
                        <label for="req-service" class="block text-sm font-semibold">Service</label>
                        <select id="req-service" name="service" class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                            <option value="">All services</option>
                            @foreach (ServiceCatalogue::SERVICES as $key => $entry)
                                <option value="{{ $key }}" @selected($service === $key)>{{ $entry['name'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="req-status" class="block text-sm font-semibold">Status</label>
                        <select id="req-status" name="status" class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                            <option value="">All statuses</option>
                            @foreach (QuotationRequestStatus::cases() as $case)
                                <option value="{{ $case->value }}" @selected($status === $case)>{{ $case->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="flex gap-2 sm:col-span-4">
                        <button type="submit" class="inline-flex min-h-11 items-center rounded-xl bg-emerald-700 px-5 text-sm font-bold text-white hover:bg-emerald-800">Apply</button>
                        <a href="{{ route('admin.quotation-requests.index') }}" class="inline-flex min-h-11 items-center rounded-xl border border-slate-300 px-5 text-sm font-bold text-slate-700">Reset</a>
                    </div>
                </form>
            </section>

            @if ($requests->isEmpty())
                <div class="rounded-3xl border border-dashed border-slate-300 bg-white p-10 text-center">
                    <h2 class="text-lg font-bold">No requests match</h2>
                    <p class="mt-2 text-sm text-slate-600">Try a different filter or clear the search.</p>
                </div>
            @else
                <div class="overflow-x-auto rounded-3xl border border-slate-200 bg-white shadow-sm">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <caption class="sr-only">Quotation requests</caption>
                        <thead class="bg-stone-50 text-left text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th scope="col" class="px-4 py-3">Reference</th>
                                <th scope="col" class="px-4 py-3">Service</th>
                                <th scope="col" class="px-4 py-3">From</th>
                                <th scope="col" class="px-4 py-3">Budget</th>
                                <th scope="col" class="px-4 py-3">Status</th>
                                <th scope="col" class="px-4 py-3">Owner</th>
                                <th scope="col" class="px-4 py-3">Received</th>
                                <th scope="col" class="px-4 py-3"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($requests as $item)
                                <tr class="align-top">
                                    <td class="px-4 py-3 font-mono text-xs text-slate-600">{{ $item->reference }}</td>
                                    <td class="px-4 py-3 text-slate-800">
                                        {{ $item->serviceLabel() }}
                                        @if ($item->quotations_count > 0)
                                            <span class="block text-[10px] text-slate-400">{{ $item->quotations_count }} {{ str('quotation')->plural($item->quotations_count) }}</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3">
                                        <p class="text-slate-800">{{ $item->contact_name }}</p>
                                        <p class="text-xs text-slate-500">{{ $item->contact_email }}</p>
                                        @if ($item->isGuest())
                                            <p class="text-[10px] font-semibold uppercase text-amber-700">Guest</p>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-xs text-slate-600">{{ $item->formattedBudget() ?? '—' }}</td>
                                    <td class="px-4 py-3">
                                        <span @class([
                                            'rounded-full px-3 py-1 text-xs font-bold',
                                            'bg-sky-50 text-sky-800' => $item->status === QuotationRequestStatus::New,
                                            'bg-amber-50 text-amber-900' => $item->status === QuotationRequestStatus::InReview,
                                            'bg-emerald-50 text-emerald-800' => $item->status === QuotationRequestStatus::Quoted,
                                            'bg-slate-100 text-slate-700' => $item->status === QuotationRequestStatus::Closed,
                                            'bg-rose-50 text-rose-800' => $item->status === QuotationRequestStatus::Cancelled,
                                        ])>{{ $item->status->label() }}</span>
                                    </td>
                                    <td class="px-4 py-3 text-xs text-slate-600">{{ $item->assignee?->name ?? 'Unassigned' }}</td>
                                    <td class="px-4 py-3 text-xs text-slate-500">{{ $item->created_at->timezone($timezone)->format('j M Y') }}</td>
                                    <td class="px-4 py-3">
                                        <a href="{{ route('admin.quotation-requests.show', $item) }}" class="inline-flex min-h-11 items-center rounded-xl border border-emerald-200 px-4 text-sm font-bold text-emerald-800">Open</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div>{{ $requests->links() }}</div>
            @endif
        </div>
    </div>
</x-app-layout>
