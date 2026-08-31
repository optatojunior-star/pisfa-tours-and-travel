@php
    use App\Enums\InvoiceStatus;
    use App\Support\Money;
@endphp
<x-app-layout>
    <x-slot name="header">
        <div>
            <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">Billing</p>
            <h1 class="mt-1 text-2xl font-bold text-slate-950">Invoices</h1>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
            @if (session('success'))
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-900" role="status">{{ session('success') }}</div>
            @endif

            <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4" aria-label="Invoice counts">
                <a href="{{ route('admin.invoices.index', ['status' => InvoiceStatus::Draft->value]) }}" @class([
                    'rounded-2xl border border-slate-200 bg-white p-5 transition hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600',
                    'ring-2 ring-emerald-600' => $status === InvoiceStatus::Draft,
                ])>
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Drafts</p>
                    <p class="mt-1 text-2xl font-black text-slate-900">{{ $counts['draft'] }}</p>
                </a>
                <a href="{{ route('admin.invoices.index') }}" class="rounded-2xl border border-slate-200 bg-white p-5 transition hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Outstanding</p>
                    <p class="mt-1 text-2xl font-black text-slate-900">{{ $counts['outstanding'] }}</p>
                </a>
                <a href="{{ route('admin.invoices.index', ['bucket' => 'overdue']) }}" @class([
                    'rounded-2xl border p-5 transition focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600',
                    'border-rose-300 bg-rose-50 hover:bg-rose-100' => $counts['overdue'] > 0,
                    'border-slate-200 bg-white hover:bg-slate-50' => $counts['overdue'] === 0,
                    'ring-2 ring-emerald-600' => $bucket === 'overdue',
                ])>
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Overdue</p>
                    <p class="mt-1 text-2xl font-black text-slate-900">{{ $counts['overdue'] }}</p>
                    <p class="mt-1 text-xs text-slate-500">Past due with money owing</p>
                </a>
                <a href="{{ route('admin.invoices.index', ['status' => InvoiceStatus::Paid->value]) }}" @class([
                    'rounded-2xl border border-slate-200 bg-white p-5 transition hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600',
                    'ring-2 ring-emerald-600' => $status === InvoiceStatus::Paid,
                ])>
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Paid</p>
                    <p class="mt-1 text-2xl font-black text-emerald-800">{{ $counts['paid'] }}</p>
                </a>
            </section>

            @if ($receivables !== [])
                <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm" aria-labelledby="receivables-heading">
                    <h2 id="receivables-heading" class="text-sm font-black uppercase tracking-wide text-slate-700">Receivables</h2>
                    {{-- Reported per currency and never summed together: UGX and
                         USD have different exponents, so one combined figure
                         would be wrong by a factor of a hundred. --}}
                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                        @foreach ($receivables as $currency => $row)
                            <div class="rounded-2xl border border-slate-200 p-4">
                                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $currency }} outstanding</p>
                                <p class="mt-1 text-xl font-black text-slate-900">{{ Money::format($row['outstanding_minor'], $currency) }}</p>
                                <p class="mt-1 text-xs text-slate-500">
                                    across {{ $row['count'] }} {{ str('invoice')->plural($row['count']) }}
                                    @if ($row['overdue_minor'] > 0)
                                        · <span class="font-bold text-rose-700">{{ Money::format($row['overdue_minor'], $currency) }} overdue</span>
                                    @endif
                                </p>
                            </div>
                        @endforeach
                    </div>
                </section>
            @endif

            <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm" aria-labelledby="invoice-filters">
                <h2 id="invoice-filters" class="sr-only">Filter invoices</h2>
                <form method="GET" action="{{ route('admin.invoices.index') }}" class="grid gap-4 sm:grid-cols-3 sm:items-end">
                    <div class="sm:col-span-2">
                        <label for="inv-q" class="block text-sm font-semibold">Search</label>
                        <input id="inv-q" name="q" type="search" maxlength="100" value="{{ $search }}"
                               placeholder="Number, title, customer"
                               class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                    </div>
                    <div>
                        <label for="inv-status" class="block text-sm font-semibold">Status</label>
                        <select id="inv-status" name="status" class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                            <option value="">All statuses</option>
                            @foreach (InvoiceStatus::cases() as $case)
                                <option value="{{ $case->value }}" @selected($status === $case)>{{ $case->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="flex gap-2 sm:col-span-3">
                        <button type="submit" class="inline-flex min-h-11 items-center rounded-xl bg-emerald-700 px-5 text-sm font-bold text-white hover:bg-emerald-800">Apply</button>
                        <a href="{{ route('admin.invoices.index') }}" class="inline-flex min-h-11 items-center rounded-xl border border-slate-300 px-5 text-sm font-bold text-slate-700">Reset</a>
                    </div>
                </form>
            </section>

            @if ($invoices->isEmpty())
                <div class="rounded-3xl border border-dashed border-slate-300 bg-white p-10 text-center">
                    <h2 class="text-lg font-bold">No invoices match</h2>
                    <p class="mt-2 text-sm text-slate-600">Invoices are raised from accepted quotations.</p>
                </div>
            @else
                <div class="overflow-x-auto rounded-3xl border border-slate-200 bg-white shadow-sm">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <caption class="sr-only">Invoices</caption>
                        <thead class="bg-stone-50 text-left text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th scope="col" class="px-4 py-3">Number</th>
                                <th scope="col" class="px-4 py-3">Subject</th>
                                <th scope="col" class="px-4 py-3">Billed to</th>
                                <th scope="col" class="px-4 py-3 text-right">Total</th>
                                <th scope="col" class="px-4 py-3 text-right">Outstanding</th>
                                <th scope="col" class="px-4 py-3">Status</th>
                                <th scope="col" class="px-4 py-3">Due</th>
                                <th scope="col" class="px-4 py-3"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($invoices as $invoice)
                                <tr class="align-top">
                                    <td class="px-4 py-3 font-mono text-xs text-slate-600">{{ $invoice->number }}</td>
                                    <td class="max-w-xs px-4 py-3 text-slate-800">{{ $invoice->title }}</td>
                                    <td class="px-4 py-3">
                                        <p class="text-slate-800">{{ $invoice->contact_name }}</p>
                                        <p class="text-xs text-slate-500">{{ $invoice->contact_email }}</p>
                                    </td>
                                    <td class="px-4 py-3 text-right tabular-nums text-slate-700">{{ $invoice->formattedTotal() }}</td>
                                    <td class="px-4 py-3 text-right font-semibold tabular-nums text-slate-900">
                                        {{ Money::format($invoice->remainingBalanceMinor(), $invoice->currency) }}
                                    </td>
                                    <td class="px-4 py-3"><x-billing-status :status="$invoice->status" :overdue="$invoice->isOverdue()" /></td>
                                    <td class="px-4 py-3 text-xs text-slate-500">{{ $invoice->due_on?->format('j M Y') ?? '—' }}</td>
                                    <td class="px-4 py-3">
                                        <a href="{{ route('admin.invoices.show', $invoice) }}" class="inline-flex min-h-11 items-center rounded-xl border border-emerald-200 px-4 text-sm font-bold text-emerald-800">Open</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div>{{ $invoices->links() }}</div>
            @endif
        </div>
    </div>
</x-app-layout>
