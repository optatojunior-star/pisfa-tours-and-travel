@php
    use App\Enums\VehicleImportStatus;
    use App\Support\Money;
@endphp
<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">Operations</p>
                <h1 class="mt-1 text-2xl font-bold text-slate-950">Vehicle imports</h1>
            </div>
            <div class="flex flex-wrap gap-2 text-sm font-bold">
                <a href="{{ route('admin.vehicle-imports.index', ['queue' => 'open']) }}" class="inline-flex min-h-11 items-center rounded-xl border border-emerald-200 bg-emerald-50 px-4 text-emerald-800">{{ $openCount }} open</a>
                <a href="{{ route('admin.vehicle-imports.index', ['queue' => 'awaiting_deposit']) }}" class="inline-flex min-h-11 items-center rounded-xl border border-amber-200 bg-amber-50 px-4 text-amber-900">{{ $awaitingDepositCount }} awaiting deposit</a>
            </div>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
            @if (session('success'))
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-900" role="status">{{ session('success') }}</div>
            @endif

            <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm" aria-labelledby="import-filters">
                <h2 id="import-filters" class="sr-only">Filter imports</h2>
                <form method="GET" action="{{ route('admin.vehicle-imports.index') }}" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4 lg:items-end">
                    <div class="lg:col-span-2">
                        <label for="imp-q" class="block text-sm font-semibold">Search</label>
                        <input id="imp-q" name="q" type="search" maxlength="100" value="{{ $filters['q'] ?? '' }}" placeholder="Reference, customer, make, model" class="mt-1 block w-full rounded-xl border-slate-300">
                    </div>
                    <div>
                        <label for="imp-status" class="block text-sm font-semibold">Status</label>
                        <select id="imp-status" name="status" class="mt-1 block w-full rounded-xl border-slate-300">
                            <option value="">All statuses</option>
                            @foreach (VehicleImportStatus::cases() as $case)
                                <option value="{{ $case->value }}" @selected(($filters['status'] ?? '') === $case->value)>{{ $case->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="imp-queue" class="block text-sm font-semibold">Queue</label>
                        <select id="imp-queue" name="queue" class="mt-1 block w-full rounded-xl border-slate-300">
                            <option value="">Everything</option>
                            <option value="open" @selected(($filters['queue'] ?? '') === 'open')>Open only</option>
                            <option value="mine" @selected(($filters['queue'] ?? '') === 'mine')>Assigned to me</option>
                            <option value="unassigned" @selected(($filters['queue'] ?? '') === 'unassigned')>Unassigned</option>
                            <option value="awaiting_deposit" @selected(($filters['queue'] ?? '') === 'awaiting_deposit')>Awaiting deposit</option>
                        </select>
                    </div>
                    <div class="flex gap-2">
                        <button class="min-h-11 rounded-xl bg-emerald-700 px-4 text-sm font-bold text-white">Filter</button>
                        <a href="{{ route('admin.vehicle-imports.index') }}" class="inline-flex min-h-11 items-center px-2 text-sm font-bold text-slate-600">Reset</a>
                    </div>
                </form>
            </section>

            @if ($orders->isEmpty())
                <div class="rounded-3xl border border-dashed border-slate-300 bg-white p-10 text-center">
                    <h2 class="text-lg font-bold">No imports match these filters</h2>
                    <p class="mt-2 text-sm text-slate-600">Adjust the search or clear the queue filter.</p>
                </div>
            @else
                <div class="overflow-x-auto rounded-3xl border border-slate-200 bg-white shadow-sm">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <caption class="sr-only">Vehicle import requests</caption>
                        <thead class="bg-slate-50 text-left text-xs font-bold uppercase tracking-wide text-slate-600">
                            <tr>
                                <th scope="col" class="px-4 py-3">Reference</th>
                                <th scope="col" class="px-4 py-3">Vehicle</th>
                                <th scope="col" class="px-4 py-3">Customer</th>
                                <th scope="col" class="px-4 py-3">Budget</th>
                                <th scope="col" class="px-4 py-3">Quoted</th>
                                <th scope="col" class="px-4 py-3">Status</th>
                                <th scope="col" class="px-4 py-3"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($orders as $order)
                                <tr>
                                    <td class="px-4 py-3 font-mono text-xs font-bold text-emerald-700">{{ $order->reference }}</td>
                                    <td class="px-4 py-3">
                                        <span class="block font-semibold text-slate-900">{{ $order->vehicleSummary() }}</span>
                                        <span class="block text-xs text-slate-500">{{ $order->body_type->label() }} · from {{ $order->origin_country }}@if ($order->units > 1) · x{{ $order->units }}@endif</span>
                                    </td>
                                    <td class="px-4 py-3">
                                        <span class="block font-semibold text-slate-900">{{ $order->contact_name }}</span>
                                        <span class="block text-xs text-slate-500">{{ $order->isGuest() ? 'Guest' : 'Customer account' }}</span>
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap">{{ $order->formattedBudget() }}</td>
                                    <td class="px-4 py-3 whitespace-nowrap font-semibold">{{ $order->hasQuote() ? Money::format((int) $order->total_price_minor, (string) $order->quote_currency) : '—' }}</td>
                                    <td class="px-4 py-3"><span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-700">{{ $order->status->label() }}</span></td>
                                    <td class="px-4 py-3 text-right">
                                        <a href="{{ route('admin.vehicle-imports.show', $order) }}" class="inline-flex min-h-11 items-center rounded-xl border border-emerald-200 px-3 text-xs font-bold text-emerald-800">Manage</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div>{{ $orders->links() }}</div>
            @endif
        </div>
    </div>
</x-app-layout>
