@php use App\Support\Money; @endphp
<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">Vehicle imports</p>
                <h1 class="mt-1 text-2xl font-bold text-slate-950">My imports</h1>
            </div>
            <a href="{{ route('vehicle-imports.create') }}" class="inline-flex min-h-11 items-center justify-center rounded-xl bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white">New import request</a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
            @if (session('success'))
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-900" role="status">{{ session('success') }}</div>
            @endif

            @if ($orders->isEmpty())
                <div class="rounded-3xl border border-dashed border-slate-300 bg-white p-10 text-center">
                    <h2 class="text-lg font-bold">No import requests yet</h2>
                    <p class="mt-2 text-sm text-slate-600">Tell us what you want to import and we will source and quote it.</p>
                    <a href="{{ route('vehicle-imports.create') }}" class="mt-5 inline-flex min-h-11 items-center justify-center rounded-xl bg-emerald-700 px-5 py-3 text-sm font-bold text-white">Request an import</a>
                </div>
            @else
                <div class="grid gap-5 md:grid-cols-2">
                    @foreach ($orders as $order)
                        <article class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <p class="font-mono text-xs font-bold text-emerald-700">{{ $order->reference }}</p>
                                    <h2 class="mt-1 text-lg font-black text-slate-950">{{ $order->vehicleSummary() }}</h2>
                                </div>
                                <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-700">{{ $order->status->label() }}</span>
                            </div>
                            <dl class="mt-4 grid grid-cols-2 gap-3 text-sm">
                                <div><dt class="text-xs text-slate-500">Budget</dt><dd class="font-semibold">{{ $order->formattedBudget() }}</dd></div>
                                <div><dt class="text-xs text-slate-500">Quoted</dt><dd class="font-semibold">{{ $order->hasQuote() ? Money::format((int) $order->total_price_minor, (string) $order->quote_currency) : 'Pending' }}</dd></div>
                            </dl>
                            <a href="{{ route('portal.vehicle-imports.show', $order) }}" class="mt-5 inline-flex min-h-11 w-full items-center justify-center rounded-xl border border-emerald-200 text-sm font-bold text-emerald-800">View import</a>
                        </article>
                    @endforeach
                </div>
                <div>{{ $orders->links() }}</div>
            @endif
        </div>
    </div>
</x-app-layout>
