<x-app-layout>
    <x-slot name="header">
        <div>
            <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">Billing</p>
            <h1 class="mt-1 text-2xl font-bold text-slate-950">My invoices</h1>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-5xl space-y-6 px-4 sm:px-6 lg:px-8">
            @if (session('success'))
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-900" role="status">{{ session('success') }}</div>
            @endif

            @if ($invoices->isEmpty())
                <div class="rounded-3xl border border-dashed border-slate-300 bg-white p-10 text-center">
                    <h2 class="text-lg font-bold">No invoices yet</h2>
                    <p class="mt-2 text-sm text-slate-600">An invoice appears here once you accept a quotation.</p>
                </div>
            @else
                <div class="space-y-4">
                    @foreach ($invoices as $invoice)
                        <article class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <p class="font-mono text-xs text-slate-500">{{ $invoice->number }}</p>
                                    <h2 class="mt-1 text-lg font-black text-slate-950">{{ $invoice->title }}</h2>
                                    <p class="mt-1 text-xs text-slate-500">
                                        Issued {{ $invoice->issued_on?->format('j M Y') ?? '—' }}
                                        @if ($invoice->due_on)
                                            · due {{ $invoice->due_on->format('j M Y') }}
                                        @endif
                                    </p>
                                </div>
                                <div class="text-right">
                                    <x-billing-status :status="$invoice->status" :overdue="$invoice->isOverdue()" />
                                    <p class="mt-2 text-lg font-black text-emerald-800">{{ $invoice->formattedTotal() }}</p>
                                    @if ($invoice->status->collectsPayment())
                                        <p class="text-xs font-semibold text-amber-700">
                                            {{ $invoice->formattedOutstanding() }} due now
                                        </p>
                                    @endif
                                </div>
                            </div>

                            <div class="mt-5">
                                <a href="{{ route('portal.invoices.show', $invoice) }}" class="inline-flex min-h-11 items-center rounded-xl bg-emerald-700 px-4 text-sm font-bold text-white hover:bg-emerald-800">
                                    {{ $invoice->acceptsPayment() ? 'View and pay' : 'View' }}
                                </a>
                            </div>
                        </article>
                    @endforeach
                </div>
                <div>{{ $invoices->links() }}</div>
            @endif
        </div>
    </div>
</x-app-layout>
