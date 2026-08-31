<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">Billing</p>
                <h1 class="mt-1 font-mono text-2xl font-bold text-slate-950">{{ $quotation->number }}</h1>
                <p class="mt-1 text-sm text-slate-600">Editing revision {{ $quotation->revision }}</p>
            </div>
            <a href="{{ route('admin.quotations.show', $quotation) }}" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700">Back to the quotation</a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8">
            @include('admin.quotations.partials.form')
        </div>
    </div>
</x-app-layout>
