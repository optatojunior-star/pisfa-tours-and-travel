<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">Quotation</p>
                <h1 class="mt-1 font-mono text-2xl font-bold text-slate-950">{{ $quotation->number }}</h1>
            </div>
            <a href="{{ route('portal.quotations.print', $quotation) }}" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-emerald-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2"><x-icon name="printer" class="h-4 w-4" />Print or save as PDF</a>
            <a href="{{ route('portal.quotations.index') }}" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700">Back to my quotations</a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-4xl space-y-6 px-4 sm:px-6 lg:px-8">
            @if (session('success'))
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-900" role="status">{{ session('success') }}</div>
            @endif

            @include('billing.quotations.partials.body')
        </div>
    </div>
</x-app-layout>
