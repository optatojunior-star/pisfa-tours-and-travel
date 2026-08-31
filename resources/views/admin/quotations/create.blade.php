<x-app-layout>
    <x-slot name="header">
        <div>
            <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">Billing</p>
            <h1 class="mt-1 text-2xl font-bold text-slate-950">New quotation</h1>
            @if ($source)
                <p class="mt-1 text-sm text-slate-600">
                    Pricing request <span class="font-mono">{{ $source->reference }}</span> — {{ $source->serviceLabel() }}
                </p>
            @endif
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8">
            @if ($source)
                <section class="mb-6 rounded-3xl border border-slate-200 bg-stone-50 p-5" aria-labelledby="source-details">
                    <h2 id="source-details" class="text-sm font-black uppercase tracking-wide text-slate-700">What the customer asked for</h2>
                    <p class="mt-2 whitespace-pre-line text-sm leading-6 text-slate-700">{{ $source->details }}</p>
                    <p class="mt-3 text-xs text-slate-500">
                        Preferred date: {{ $source->preferred_date?->format('j M Y') ?? 'flexible' }}
                        · Party size: {{ $source->party_size ?? 'not stated' }}
                        · Budget: {{ $source->formattedBudget() ?? 'not stated' }}
                    </p>
                </section>
            @endif

            @include('admin.quotations.partials.form')
        </div>
    </div>
</x-app-layout>
