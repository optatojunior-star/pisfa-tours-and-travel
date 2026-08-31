@php
    use App\Enums\InvoiceStatus;

    $timezone = config('pisfa.business_timezone', 'Africa/Kampala');
@endphp

<article class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">Invoice</p>
            <h1 class="mt-1 font-mono text-2xl font-black text-slate-950">{{ $invoice->number }}</h1>
            <p class="mt-2 text-lg font-bold text-slate-900">{{ $invoice->title }}</p>
        </div>
        <x-billing-status :status="$invoice->status" :overdue="$invoice->isOverdue()" />
    </div>

    <dl class="mt-6 grid gap-4 border-y border-slate-200 py-5 text-sm sm:grid-cols-3">
        <div>
            <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Issued</dt>
            <dd class="mt-1 text-slate-800">{{ $invoice->issued_on?->format('j M Y') ?? '—' }}</dd>
        </div>
        <div>
            <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Due</dt>
            <dd class="mt-1 text-slate-800">
                {{ $invoice->due_on?->format('j M Y') ?? '—' }}
                @if ($invoice->isOverdue())
                    <span class="ml-1 font-bold text-rose-700">({{ $invoice->daysOverdue() }} days ago)</span>
                @endif
            </dd>
        </div>
        <div>
            <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                {{ $invoice->status->collectsPayment() ? $invoice->nextPaymentLabel().' due now' : 'Total' }}
            </dt>
            <dd class="mt-1 text-lg font-black text-emerald-800">
                {{ $invoice->status->collectsPayment() ? $invoice->formattedOutstanding() : $invoice->formattedTotal() }}
            </dd>
        </div>
    </dl>

    <div class="mt-6">
        <h2 class="text-sm font-black uppercase tracking-wide text-slate-700">Charges</h2>
        <div class="mt-3">
            <x-billing-lines :document="$invoice" />
        </div>
    </div>

    @if ($invoice->notes)
        <section class="mt-6" aria-labelledby="invoice-notes">
            <h2 id="invoice-notes" class="text-sm font-black uppercase tracking-wide text-slate-700">Notes</h2>
            <p class="mt-2 whitespace-pre-line text-sm leading-6 text-slate-700">{{ $invoice->notes }}</p>
        </section>
    @endif

    @if ($invoice->terms)
        <section class="mt-6" aria-labelledby="invoice-terms">
            <h2 id="invoice-terms" class="text-sm font-black uppercase tracking-wide text-slate-700">Payment terms</h2>
            <p class="mt-2 whitespace-pre-line text-sm leading-6 text-slate-700">{{ $invoice->terms }}</p>
        </section>
    @endif
</article>

@if ($invoice->status === InvoiceStatus::Paid)
    <section class="rounded-3xl border border-emerald-200 bg-emerald-50/60 p-6 shadow-sm">
        <h2 class="text-lg font-black text-slate-950">Paid in full</h2>
        <p class="mt-2 text-sm text-slate-700">
            Received {{ $invoice->paid_at?->timezone($timezone)->format('j M Y') }}. Thank you.
        </p>
    </section>
@elseif (in_array($invoice->status, [InvoiceStatus::Cancelled, InvoiceStatus::Void], true))
    <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
        <h2 class="text-lg font-black text-slate-950">
            This invoice is {{ mb_strtolower($invoice->status->label()) }}
        </h2>
        <p class="mt-2 text-sm text-slate-700">Nothing is payable against it. Contact us if you were expecting to pay.</p>
    </section>
@elseif ($isGuestView)
    <section class="rounded-3xl border border-emerald-200 bg-emerald-50/60 p-6 shadow-sm">
        <h2 class="text-lg font-black text-slate-950">Paying this invoice</h2>
        <p class="mt-2 text-sm text-slate-700">
            Payments are made from a PISFA account, so the transaction is tied to a verified identity and your
            receipts stay in one place. Create an account using <strong>{{ $invoice->contact_email }}</strong>
            and our team will link this invoice to it.
        </p>
        <div class="mt-5 flex flex-wrap gap-3">
            <a href="{{ route('register') }}" class="inline-flex min-h-11 items-center justify-center rounded-xl bg-emerald-800 px-5 py-3 text-sm font-bold text-white hover:bg-emerald-900">Create an account</a>
            <a href="{{ route('contact') }}" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-emerald-200 px-5 py-3 text-sm font-bold text-emerald-800 hover:bg-emerald-50">Contact our team</a>
        </div>
    </section>
@else
    <x-pay-now :payable="$invoice" />
@endif
