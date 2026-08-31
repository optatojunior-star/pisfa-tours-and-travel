@php
    use App\Enums\PaymentProvider;
    use App\Enums\PaymentStatus;
    use App\Support\Money;

    $timezone = config('pisfa.business_timezone', 'Africa/Kampala');
    $base = config('payments.base_currency', 'UGX');
@endphp
<x-app-layout>
    <x-slot name="header">
        <div>
            <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">Finance</p>
            <h1 class="mt-1 text-2xl font-bold text-slate-950">Transactions</h1>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
            @if (session('success'))
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-900" role="status">{{ session('success') }}</div>
            @endif

            <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4" aria-label="Reconciliation summary">
                <div class="rounded-2xl border border-slate-200 bg-white p-5">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Collected</p>
                    <p class="mt-1 text-2xl font-black text-emerald-800">{{ Money::format($totals['collected_minor'], $base) }}</p>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-white p-5">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Refunded</p>
                    <p class="mt-1 text-2xl font-black text-rose-800">{{ Money::format($totals['refunded_minor'], $base) }}</p>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-white p-5">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Net</p>
                    <p class="mt-1 text-2xl font-black text-slate-900">{{ Money::format($totals['net_minor'], $base) }}</p>
                </div>
                <a href="{{ route('admin.payments.index', ['bucket' => 'unreconciled']) }}" @class([
                    'rounded-2xl border p-5 transition focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600',
                    'border-amber-300 bg-amber-50 hover:bg-amber-100' => $totals['unreconciled_count'] > 0,
                    'border-slate-200 bg-white hover:bg-slate-50' => $totals['unreconciled_count'] === 0,
                ])>
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Unreconciled</p>
                    <p class="mt-1 text-2xl font-black text-slate-900">{{ $totals['unreconciled_count'] }}</p>
                    <p class="mt-1 text-xs text-slate-500">Settled, not credited to a service</p>
                </a>
            </section>

            <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm" aria-labelledby="payment-filters">
                <h2 id="payment-filters" class="sr-only">Filter transactions</h2>
                <form method="GET" action="{{ route('admin.payments.index') }}" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4 lg:items-end">
                    <div class="lg:col-span-2">
                        <label for="pay-q" class="block text-sm font-semibold">Search</label>
                        <input id="pay-q" name="q" type="search" maxlength="100" value="{{ $filters['q'] ?? '' }}" placeholder="Reference, transaction id, customer" class="mt-1 block w-full rounded-xl border-slate-300">
                    </div>
                    <div>
                        <label for="pay-status" class="block text-sm font-semibold">Status</label>
                        <select id="pay-status" name="status" class="mt-1 block w-full rounded-xl border-slate-300">
                            <option value="">All statuses</option>
                            @foreach (PaymentStatus::cases() as $case)
                                <option value="{{ $case->value }}" @selected(($filters['status'] ?? '') === $case->value)>{{ $case->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="pay-provider" class="block text-sm font-semibold">Method</label>
                        <select id="pay-provider" name="provider" class="mt-1 block w-full rounded-xl border-slate-300">
                            <option value="">All methods</option>
                            @foreach (PaymentProvider::cases() as $case)
                                <option value="{{ $case->value }}" @selected(($filters['provider'] ?? '') === $case->value)>{{ $case->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="pay-bucket" class="block text-sm font-semibold">View</label>
                        <select id="pay-bucket" name="bucket" class="mt-1 block w-full rounded-xl border-slate-300">
                            <option value="">Everything</option>
                            <option value="settled" @selected(($filters['bucket'] ?? '') === 'settled')>Settled</option>
                            <option value="in_flight" @selected(($filters['bucket'] ?? '') === 'in_flight')>Awaiting outcome</option>
                            <option value="refunded" @selected(($filters['bucket'] ?? '') === 'refunded')>Refunded</option>
                            <option value="unreconciled" @selected(($filters['bucket'] ?? '') === 'unreconciled')>Unreconciled</option>
                        </select>
                    </div>
                    <div>
                        <label for="pay-from" class="block text-sm font-semibold">From</label>
                        <input id="pay-from" name="from" type="date" value="{{ $filters['from'] ?? '' }}" class="mt-1 block w-full rounded-xl border-slate-300">
                    </div>
                    <div>
                        <label for="pay-to" class="block text-sm font-semibold">To</label>
                        <input id="pay-to" name="to" type="date" value="{{ $filters['to'] ?? '' }}" class="mt-1 block w-full rounded-xl border-slate-300">
                    </div>
                    <div class="flex gap-2">
                        <button class="min-h-11 rounded-xl bg-emerald-700 px-4 text-sm font-bold text-white">Filter</button>
                        <a href="{{ route('admin.payments.index') }}" class="inline-flex min-h-11 items-center px-2 text-sm font-bold text-slate-600">Reset</a>
                    </div>
                </form>
            </section>

            @if ($payments->isEmpty())
                <div class="rounded-3xl border border-dashed border-slate-300 bg-white p-10 text-center">
                    <h2 class="text-lg font-bold">No transactions match these filters</h2>
                    <p class="mt-2 text-sm text-slate-600">Adjust the search or clear the view filter.</p>
                </div>
            @else
                <div class="overflow-x-auto rounded-3xl border border-slate-200 bg-white shadow-sm">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <caption class="sr-only">Payment transactions</caption>
                        <thead class="bg-slate-50 text-left text-xs font-bold uppercase tracking-wide text-slate-600">
                            <tr>
                                <th scope="col" class="px-4 py-3">Reference</th>
                                <th scope="col" class="px-4 py-3">Customer</th>
                                <th scope="col" class="px-4 py-3">For</th>
                                <th scope="col" class="px-4 py-3">Method</th>
                                <th scope="col" class="px-4 py-3">Amount</th>
                                <th scope="col" class="px-4 py-3">Status</th>
                                <th scope="col" class="px-4 py-3">Started</th>
                                <th scope="col" class="px-4 py-3"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($payments as $payment)
                                <tr>
                                    <td class="px-4 py-3 font-mono text-xs font-bold text-emerald-700">{{ $payment->reference }}</td>
                                    <td class="px-4 py-3">{{ $payment->customer?->name ?? 'Guest' }}</td>
                                    <td class="px-4 py-3 text-xs text-slate-600">{{ $payment->payable?->paymentReference() ?? '—' }}</td>
                                    <td class="px-4 py-3 text-xs">{{ $payment->provider->label() }}</td>
                                    <td class="px-4 py-3 whitespace-nowrap font-semibold">
                                        {{ $payment->formattedAmount() }}
                                        @if ($payment->refunded_amount_minor > 0)
                                            <span class="block text-xs font-semibold text-rose-700">less {{ Money::format($payment->refunded_amount_minor, $payment->currency) }} refunded</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3"><span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-700">{{ $payment->status->label() }}</span></td>
                                    <td class="px-4 py-3 whitespace-nowrap text-xs">{{ $payment->created_at->timezone($timezone)->format('j M Y, H:i') }}</td>
                                    <td class="px-4 py-3 text-right">
                                        <a href="{{ route('admin.payments.show', $payment) }}" class="inline-flex min-h-11 items-center rounded-xl border border-emerald-200 px-3 text-xs font-bold text-emerald-800">Open</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div>{{ $payments->links() }}</div>
            @endif
        </div>
    </div>
</x-app-layout>
