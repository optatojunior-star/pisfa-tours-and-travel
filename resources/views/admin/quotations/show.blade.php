@php
    use App\Enums\QuotationStatus;

    $timezone = config('pisfa.business_timezone', 'Africa/Kampala');
    $user = auth()->user();
@endphp
<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">Billing</p>
                <h1 class="mt-1 font-mono text-2xl font-bold text-slate-950">{{ $quotation->number }}</h1>
                <p class="mt-1 text-sm text-slate-600">{{ $quotation->title }}</p>
            </div>
            <a href="{{ route('admin.quotations.index') }}" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700">Back to quotations</a>
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
                    <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm" aria-labelledby="qtn-summary">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <h2 id="qtn-summary" class="text-lg font-black text-slate-950">
                                Revision {{ $quotation->revision }}
                            </h2>
                            <x-billing-status :status="$quotation->status" />
                        </div>

                        <div class="mt-5">
                            <x-billing-lines :document="$quotation" />
                        </div>

                        @if ($quotation->deposit_minor)
                            <p class="mt-4 rounded-xl bg-stone-100 p-3 text-sm text-slate-700">
                                Payable in two stages: a deposit of
                                <span class="font-bold">{{ \App\Support\Money::format($quotation->deposit_minor, $quotation->currency) }}</span>,
                                then the balance.
                            </p>
                        @endif

                        @if ($quotation->notes)
                            <div class="mt-5">
                                <h3 class="text-sm font-black uppercase tracking-wide text-slate-700">Notes to the customer</h3>
                                <p class="mt-2 whitespace-pre-line text-sm leading-6 text-slate-700">{{ $quotation->notes }}</p>
                            </div>
                        @endif

                        @if ($quotation->internal_notes)
                            <div class="mt-5 rounded-2xl border border-amber-200 bg-amber-50 p-4">
                                <h3 class="text-xs font-black uppercase tracking-wide text-amber-800">Internal notes</h3>
                                <p class="mt-1 whitespace-pre-line text-sm text-slate-800">{{ $quotation->internal_notes }}</p>
                                <p class="mt-2 text-xs text-amber-800">Never shown to the customer or rendered on the PDF.</p>
                            </div>
                        @endif
                    </section>

                    @if ($quotation->status === QuotationStatus::Declined && $quotation->decline_reason)
                        <section class="rounded-3xl border border-rose-200 bg-rose-50 p-6" aria-labelledby="qtn-declined">
                            <h2 id="qtn-declined" class="text-lg font-black text-slate-950">Declined by the customer</h2>
                            <p class="mt-2 text-sm text-slate-800">{{ $quotation->decline_reason }}</p>
                            <p class="mt-1 text-xs text-slate-500">{{ $quotation->declined_at?->timezone($timezone)->format('j M Y H:i') }}</p>
                        </section>
                    @endif

                    @if ($quotation->cancellation_reason)
                        <section class="rounded-3xl border border-slate-200 bg-white p-6" aria-labelledby="qtn-cancelled">
                            <h2 id="qtn-cancelled" class="text-lg font-black text-slate-950">Cancelled</h2>
                            <p class="mt-2 text-sm text-slate-800">{{ $quotation->cancellation_reason }}</p>
                        </section>
                    @endif
                </div>

                <div class="space-y-6">
                    <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm" aria-labelledby="qtn-actions">
                        <h2 id="qtn-actions" class="text-lg font-black text-slate-950">Actions</h2>
                        <div class="mt-4 space-y-3">
                            @can('update', $quotation)
                                <a href="{{ route('admin.quotations.edit', $quotation) }}" class="inline-flex min-h-11 w-full items-center justify-center rounded-xl border border-emerald-200 px-4 text-sm font-bold text-emerald-800">Edit the draft</a>
                            @endcan

                            @can('send', $quotation)
                                <form method="POST" action="{{ route('admin.quotations.send', $quotation) }}">
                                    @csrf
                                    <button type="submit" class="inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-emerald-700 px-4 text-sm font-bold text-white hover:bg-emerald-800">
                                        {{ $quotation->revision > 1 ? 'Send revision '.$quotation->revision : 'Send to the customer' }}
                                    </button>
                                </form>
                            @endcan

                            @can('revise', $quotation)
                                <form method="POST" action="{{ route('admin.quotations.revise', $quotation) }}">
                                    @csrf
                                    <button type="submit" class="inline-flex min-h-11 w-full items-center justify-center rounded-xl border border-slate-300 px-4 text-sm font-bold text-slate-700">Pull back and revise</button>
                                </form>
                            @endcan

                            @can('convert', $quotation)
                                @if ($quotation->invoice === null)
                                    <form method="POST" action="{{ route('admin.quotations.convert', $quotation) }}">
                                        @csrf
                                        <button type="submit" class="inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-amber-500 px-4 text-sm font-black text-emerald-950 hover:bg-amber-400">Raise an invoice</button>
                                    </form>
                                @endif
                            @endcan

                            @if ($quotation->invoice)
                                <a href="{{ route('admin.invoices.show', $quotation->invoice) }}" class="inline-flex min-h-11 w-full items-center justify-center rounded-xl border border-emerald-200 px-4 text-sm font-bold text-emerald-800">
                                    Invoice {{ $quotation->invoice->number }}
                                </a>
                            @endif

                            {{-- Print works whether or not a PDF has been
                                 filed yet, so a draft can still be shown to
                                 somebody across a desk. --}}
                            <a href="{{ route('admin.quotations.print', $quotation) }}" target="_blank" rel="noopener"
                               class="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-xl border border-emerald-200 px-4 text-sm font-bold text-emerald-800 hover:bg-emerald-50">
                                <x-icon name="printer" class="h-4 w-4" />
                                Print or save as PDF
                            </a>

                            @if ($document)
                                <a href="{{ route('documents.show', $document) }}" class="inline-flex min-h-11 w-full items-center justify-center rounded-xl border border-slate-300 px-4 text-sm font-bold text-slate-700">
                                    Download PDF (v{{ $document->version }})
                                </a>
                            @endif
                        </div>

                        @can('cancel', $quotation)
                            <form method="POST" action="{{ route('admin.quotations.cancel', $quotation) }}" class="mt-5 space-y-2 border-t border-slate-200 pt-5">
                                @csrf
                                <label for="cancel-reason" class="block text-sm font-semibold text-slate-800">Cancel this quotation</label>
                                <input id="cancel-reason" name="reason" type="text" required minlength="5" maxlength="255"
                                       placeholder="Why is it being withdrawn?"
                                       class="block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                                <x-input-error :messages="$errors->get('reason')" />
                                <button type="submit" class="inline-flex min-h-11 w-full items-center justify-center rounded-xl border border-rose-300 px-4 text-sm font-bold text-rose-700 hover:bg-rose-50">Cancel the quotation</button>
                            </form>
                        @endcan
                    </section>

                    <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm" aria-labelledby="qtn-context">
                        <h2 id="qtn-context" class="text-lg font-black text-slate-950">Details</h2>
                        <dl class="mt-4 space-y-3 text-sm">
                            <div>
                                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Addressed to</dt>
                                <dd class="text-slate-800">{{ $quotation->contact_name }}</dd>
                                <dd class="text-xs text-slate-500">{{ $quotation->contact_email }}</dd>
                                @if ($quotation->isGuest())
                                    <dd class="mt-1 text-xs font-bold uppercase text-amber-700">Guest — no account</dd>
                                @endif
                            </div>
                            @if ($quotation->request)
                                <div>
                                    <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">From request</dt>
                                    <dd>
                                        <a href="{{ route('admin.quotation-requests.show', $quotation->request) }}" class="font-mono text-xs text-emerald-800 underline">{{ $quotation->request->reference }}</a>
                                    </dd>
                                </div>
                            @endif
                            <div>
                                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Valid until</dt>
                                <dd class="text-slate-800">{{ $quotation->valid_until?->format('j M Y') ?? 'No expiry' }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Sent</dt>
                                <dd class="text-slate-800">{{ $quotation->sent_at?->timezone($timezone)->format('j M Y H:i') ?? 'Not yet' }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Drafted by</dt>
                                <dd class="text-slate-800">{{ $quotation->createdBy?->name ?? '—' }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Customer link</dt>
                                <dd class="break-all text-xs text-slate-600">{{ $quotation->viewUrl() }}</dd>
                            </div>
                        </dl>
                    </section>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
