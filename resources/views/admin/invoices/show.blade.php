@php
    use App\Support\Money;

    $timezone = config('pisfa.business_timezone', 'Africa/Kampala');
@endphp
<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">Billing</p>
                <h1 class="mt-1 font-mono text-2xl font-bold text-slate-950">{{ $invoice->number }}</h1>
                <p class="mt-1 text-sm text-slate-600">{{ $invoice->title }}</p>
            </div>
            <a href="{{ route('admin.invoices.index') }}" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700">Back to invoices</a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-6xl space-y-6 px-4 sm:px-6 lg:px-8">
            @if (session('success'))
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-900" role="status">{{ session('success') }}</div>
            @endif

            @if ($errors->any())
                <div class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800" role="alert">
                    <ul class="list-disc pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                </div>
            @endif

            <div class="grid gap-6 lg:grid-cols-3">
                <div class="space-y-6 lg:col-span-2">
                    <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm" aria-labelledby="inv-summary">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <h2 id="inv-summary" class="text-lg font-black text-slate-950">Charges</h2>
                            <x-billing-status :status="$invoice->status" :overdue="$invoice->isOverdue()" />
                        </div>

                        <div class="mt-5">
                            <x-billing-lines :document="$invoice" />
                        </div>

                        @if ($invoice->internal_notes)
                            <div class="mt-5 rounded-2xl border border-amber-200 bg-amber-50 p-4">
                                <h3 class="text-xs font-black uppercase tracking-wide text-amber-800">Internal notes</h3>
                                <p class="mt-1 whitespace-pre-line text-sm text-slate-800">{{ $invoice->internal_notes }}</p>
                            </div>
                        @endif

                        @if ($invoice->closure_reason)
                            <p class="mt-5 rounded-xl border border-slate-200 bg-stone-50 p-4 text-sm text-slate-700">
                                <span class="font-bold">{{ $invoice->status->label() }}:</span> {{ $invoice->closure_reason }}
                            </p>
                        @endif
                    </section>

                    <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm" aria-labelledby="inv-payments">
                        <h2 id="inv-payments" class="text-lg font-black text-slate-950">Payments</h2>
                        @if ($invoice->payments->isEmpty())
                            <p class="mt-3 text-sm text-slate-600">No payment has been started against this invoice.</p>
                        @else
                            <ul class="mt-4 space-y-3">
                                @foreach ($invoice->payments as $payment)
                                    <li class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-slate-200 p-4 text-sm">
                                        <div>
                                            <a href="{{ route('admin.payments.show', $payment) }}" class="font-mono text-xs text-emerald-800 underline">{{ $payment->reference }}</a>
                                            <p class="mt-1 text-xs text-slate-500">
                                                {{ $payment->provider->label() }}
                                                · {{ $payment->created_at->timezone($timezone)->format('j M Y H:i') }}
                                            </p>
                                        </div>
                                        <div class="text-right">
                                            <p class="font-semibold tabular-nums text-slate-900">{{ $payment->formattedAmount() }}</p>
                                            <p class="text-xs text-slate-500">{{ $payment->status->label() }}</p>
                                        </div>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </section>
                </div>

                <div class="space-y-6">
                    <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm" aria-labelledby="inv-actions">
                        <h2 id="inv-actions" class="text-lg font-black text-slate-950">Actions</h2>
                        <div class="mt-4 space-y-3">
                            @can('issue', $invoice)
                                <form method="POST" action="{{ route('admin.invoices.issue', $invoice) }}">
                                    @csrf
                                    <button type="submit" class="inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-emerald-700 px-4 text-sm font-bold text-white hover:bg-emerald-800">Issue to the customer</button>
                                </form>
                            @endcan

                            @if ($document)
                                <a href="{{ route('documents.show', $document) }}" class="inline-flex min-h-11 w-full items-center justify-center rounded-xl border border-slate-300 px-4 text-sm font-bold text-slate-700">
                                    Download PDF (v{{ $document->version }})
                                </a>
                            @endif

                            @if ($invoice->quotation)
                                <a href="{{ route('admin.quotations.show', $invoice->quotation) }}" class="inline-flex min-h-11 w-full items-center justify-center rounded-xl border border-emerald-200 px-4 text-sm font-bold text-emerald-800">
                                    Quotation {{ $invoice->quotation->number }}
                                </a>
                            @endif
                        </div>

                        @can('cancel', $invoice)
                            <form method="POST" action="{{ route('admin.invoices.cancel', $invoice) }}" class="mt-5 space-y-2 border-t border-slate-200 pt-5">
                                @csrf
                                <label for="cancel-reason" class="block text-sm font-semibold text-slate-800">Cancel this invoice</label>
                                <input id="cancel-reason" name="reason" type="text" required minlength="5" maxlength="255"
                                       placeholder="Why is it being withdrawn?"
                                       class="block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                                <p class="text-xs text-slate-500">Only possible while nothing has been received against it.</p>
                                <button type="submit" class="inline-flex min-h-11 w-full items-center justify-center rounded-xl border border-rose-300 px-4 text-sm font-bold text-rose-700 hover:bg-rose-50">Cancel the invoice</button>
                            </form>
                        @endcan

                        @can('void', $invoice)
                            <form method="POST" action="{{ route('admin.invoices.void', $invoice) }}" class="mt-5 space-y-2 border-t border-slate-200 pt-5">
                                @csrf
                                <label for="void-reason" class="block text-sm font-semibold text-slate-800">Void this invoice</label>
                                <input id="void-reason" name="reason" type="text" required minlength="5" maxlength="255"
                                       placeholder="Why is it being written off?"
                                       class="block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                                <p class="text-xs text-slate-500">
                                    A correction to money already recognised. Recorded permanently in the audit log.
                                    Any payment received stays on record and is not reversed by this.
                                </p>
                                <button type="submit" class="inline-flex min-h-11 w-full items-center justify-center rounded-xl border border-rose-400 bg-rose-50 px-4 text-sm font-bold text-rose-800 hover:bg-rose-100">Void the invoice</button>
                            </form>
                        @endcan
                    </section>

                    <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm" aria-labelledby="inv-context">
                        <h2 id="inv-context" class="text-lg font-black text-slate-950">Details</h2>
                        <dl class="mt-4 space-y-3 text-sm">
                            <div>
                                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Billed to</dt>
                                <dd class="text-slate-800">{{ $invoice->contact_name }}</dd>
                                <dd class="text-xs text-slate-500">{{ $invoice->contact_email }}</dd>
                                @if ($invoice->isGuest())
                                    <dd class="mt-1 text-xs font-bold uppercase text-amber-700">Guest — no account</dd>
                                @endif
                            </div>
                            <div>
                                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Issued</dt>
                                <dd class="text-slate-800">{{ $invoice->issued_on?->format('j M Y') ?? 'Not yet' }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Due</dt>
                                <dd class="text-slate-800">
                                    {{ $invoice->due_on?->format('j M Y') ?? '—' }}
                                    @if ($invoice->isOverdue())
                                        <span class="font-bold text-rose-700">({{ $invoice->daysOverdue() }} days late)</span>
                                    @endif
                                </dd>
                            </div>
                            @if ($invoice->hasDeposit())
                                <div>
                                    <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Deposit</dt>
                                    <dd class="text-slate-800">{{ Money::format((int) $invoice->deposit_minor, $invoice->currency) }}</dd>
                                    <dd class="text-xs text-slate-500">{{ $invoice->depositIsSettled() ? 'Received' : 'Outstanding' }}</dd>
                                </div>
                            @endif
                            <div>
                                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Raised by</dt>
                                <dd class="text-slate-800">{{ $invoice->createdBy?->name ?? '—' }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Customer link</dt>
                                <dd class="break-all text-xs text-slate-600">{{ $invoice->viewUrl() }}</dd>
                            </div>
                        </dl>
                    </section>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
