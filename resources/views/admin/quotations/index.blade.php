@php
    use App\Enums\QuotationStatus;

    $timezone = config('pisfa.business_timezone', 'Africa/Kampala');
@endphp
<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">Billing</p>
                <h1 class="mt-1 text-2xl font-bold text-slate-950">Quotations</h1>
            </div>
            <a href="{{ route('admin.quotations.create') }}" class="inline-flex min-h-11 items-center justify-center rounded-xl bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-emerald-800">New quotation</a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
            @if (session('success'))
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-900" role="status">{{ session('success') }}</div>
            @endif

            <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4" aria-label="Quotation counts">
                @foreach ([
                    QuotationStatus::Draft->value => ['Drafts', $counts['draft']],
                    QuotationStatus::Sent->value => ['Awaiting a decision', $counts['sent']],
                    QuotationStatus::Accepted->value => ['Accepted', $counts['accepted']],
                    QuotationStatus::Declined->value => ['Declined', $counts['declined']],
                ] as $value => [$label, $count])
                    <a href="{{ route('admin.quotations.index', ['status' => $value]) }}" @class([
                        'rounded-2xl border p-5 transition focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600',
                        'border-slate-200 bg-white hover:bg-slate-50',
                        'ring-2 ring-emerald-600' => $status?->value === $value,
                    ])>
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $label }}</p>
                        <p class="mt-1 text-2xl font-black text-slate-900">{{ $count }}</p>
                    </a>
                @endforeach
            </section>

            <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm" aria-labelledby="quotation-filters">
                <h2 id="quotation-filters" class="sr-only">Filter quotations</h2>
                <form method="GET" action="{{ route('admin.quotations.index') }}" class="grid gap-4 sm:grid-cols-3 sm:items-end">
                    <div class="sm:col-span-2">
                        <label for="qtn-q" class="block text-sm font-semibold">Search</label>
                        <input id="qtn-q" name="q" type="search" maxlength="100" value="{{ $search }}"
                               placeholder="Number, title, customer"
                               class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                    </div>
                    <div>
                        <label for="qtn-status" class="block text-sm font-semibold">Status</label>
                        <select id="qtn-status" name="status" class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                            <option value="">All statuses</option>
                            @foreach (QuotationStatus::cases() as $case)
                                <option value="{{ $case->value }}" @selected($status === $case)>{{ $case->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="flex gap-2 sm:col-span-3">
                        <button type="submit" class="inline-flex min-h-11 items-center rounded-xl bg-emerald-700 px-5 text-sm font-bold text-white hover:bg-emerald-800">Apply</button>
                        <a href="{{ route('admin.quotations.index') }}" class="inline-flex min-h-11 items-center rounded-xl border border-slate-300 px-5 text-sm font-bold text-slate-700">Reset</a>
                    </div>
                </form>
            </section>

            @if ($quotations->isEmpty())
                <div class="rounded-3xl border border-dashed border-slate-300 bg-white p-10 text-center">
                    <h2 class="text-lg font-bold">No quotations match</h2>
                    <p class="mt-2 text-sm text-slate-600">Try a different status or clear the search.</p>
                </div>
            @else
                <div class="overflow-x-auto rounded-3xl border border-slate-200 bg-white shadow-sm">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <caption class="sr-only">Quotations</caption>
                        <thead class="bg-stone-50 text-left text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th scope="col" class="px-4 py-3">Number</th>
                                <th scope="col" class="px-4 py-3">Subject</th>
                                <th scope="col" class="px-4 py-3">Addressed to</th>
                                <th scope="col" class="px-4 py-3 text-right">Total</th>
                                <th scope="col" class="px-4 py-3">Status</th>
                                <th scope="col" class="px-4 py-3">Valid until</th>
                                <th scope="col" class="px-4 py-3"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($quotations as $quotation)
                                <tr class="align-top">
                                    <td class="px-4 py-3 font-mono text-xs text-slate-600">
                                        {{ $quotation->number }}
                                        @if ($quotation->revision > 1)
                                            <span class="block text-[10px] text-slate-400">rev {{ $quotation->revision }}</span>
                                        @endif
                                    </td>
                                    <td class="max-w-xs px-4 py-3 text-slate-800">{{ $quotation->title }}</td>
                                    <td class="px-4 py-3">
                                        <p class="text-slate-800">{{ $quotation->contact_name }}</p>
                                        <p class="text-xs text-slate-500">{{ $quotation->contact_email }}</p>
                                        @if ($quotation->isGuest())
                                            <p class="text-[10px] font-semibold uppercase text-amber-700">Guest</p>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-right font-semibold tabular-nums text-slate-900">{{ $quotation->formattedTotal() }}</td>
                                    <td class="px-4 py-3"><x-billing-status :status="$quotation->status" /></td>
                                    <td class="px-4 py-3 text-xs text-slate-500">{{ $quotation->valid_until?->format('j M Y') ?? '—' }}</td>
                                    <td class="px-4 py-3">
                                        <a href="{{ route('admin.quotations.show', $quotation) }}" class="inline-flex min-h-11 items-center rounded-xl border border-emerald-200 px-4 text-sm font-bold text-emerald-800">Open</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div>{{ $quotations->links() }}</div>
            @endif
        </div>
    </div>
</x-app-layout>
