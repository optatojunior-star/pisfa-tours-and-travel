@php
    $timezone = config('pisfa.business_timezone', 'Africa/Kampala');
@endphp
<x-app-layout>
    <x-slot name="header">
        <div>
            <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">Billing</p>
            <h1 class="mt-1 text-2xl font-bold text-slate-950">My quotations</h1>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-5xl space-y-6 px-4 sm:px-6 lg:px-8">
            @if (session('success'))
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-900" role="status">{{ session('success') }}</div>
            @endif

            @if ($quotations->isEmpty())
                <div class="rounded-3xl border border-dashed border-slate-300 bg-white p-10 text-center">
                    <h2 class="text-lg font-bold">No quotations yet</h2>
                    <p class="mt-2 text-sm text-slate-600">Ask us to price something and your quotation will appear here.</p>
                    <a href="{{ route('request-quotation') }}" class="mt-5 inline-flex min-h-11 items-center rounded-xl bg-emerald-700 px-5 text-sm font-bold text-white hover:bg-emerald-800">Request a quotation</a>
                </div>
            @else
                <div class="space-y-4">
                    @foreach ($quotations as $quotation)
                        <article class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <p class="font-mono text-xs text-slate-500">{{ $quotation->number }}</p>
                                    <h2 class="mt-1 text-lg font-black text-slate-950">{{ $quotation->title }}</h2>
                                    <p class="mt-1 text-xs text-slate-500">
                                        Sent {{ $quotation->sent_at?->timezone($timezone)->format('j M Y') ?? '—' }}
                                        @if ($quotation->valid_until)
                                            · valid until {{ $quotation->valid_until->format('j M Y') }}
                                        @endif
                                    </p>
                                </div>
                                <div class="text-right">
                                    <x-billing-status :status="$quotation->status" />
                                    <p class="mt-2 text-lg font-black text-emerald-800">{{ $quotation->formattedTotal() }}</p>
                                </div>
                            </div>

                            <div class="mt-5 flex flex-wrap gap-2">
                                <a href="{{ route('portal.quotations.show', $quotation) }}" class="inline-flex min-h-11 items-center rounded-xl bg-emerald-700 px-4 text-sm font-bold text-white hover:bg-emerald-800">
                                    {{ $quotation->awaitsResponse() ? 'Review and respond' : 'View' }}
                                </a>
                                @if ($quotation->invoice && $quotation->invoice->status->isVisibleToCustomer())
                                    <a href="{{ route('portal.invoices.show', $quotation->invoice) }}" class="inline-flex min-h-11 items-center rounded-xl border border-emerald-200 px-4 text-sm font-bold text-emerald-800">
                                        Invoice {{ $quotation->invoice->number }}
                                    </a>
                                @endif
                            </div>
                        </article>
                    @endforeach
                </div>
                <div>{{ $quotations->links() }}</div>
            @endif
        </div>
    </div>
</x-app-layout>
