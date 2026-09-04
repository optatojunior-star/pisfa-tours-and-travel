@php
    use App\Enums\InvoiceStatus;

    $settled = $invoice->settledAmountMinor();

    // The status band. Void and cancelled are the same message to the reader —
    // do not pay this — but they are different records, and a printed copy is
    // the one people argue over later, so it says which.
    $stamp = match ($invoice->status) {
        InvoiceStatus::Paid => ['Paid in full', 'bg-emerald-50 text-emerald-800 ring-emerald-200'],
        InvoiceStatus::PartiallyPaid => ['Part paid', 'bg-amber-50 text-amber-800 ring-amber-200'],
        InvoiceStatus::Void => ['Void', 'bg-rose-50 text-rose-800 ring-rose-200'],
        InvoiceStatus::Cancelled => ['Cancelled', 'bg-rose-50 text-rose-800 ring-rose-200'],
        default => null,
    };
@endphp

<header class="flex flex-wrap items-start justify-between gap-6 border-b-2 border-brand-700 pb-6">
    <div>
        <p class="text-xl font-black tracking-tight text-brand-800">{{ $brand['name'] }}</p>
        @if (filled($brand['tagline']))
            <p class="mt-0.5 text-sm text-ink-500">{{ $brand['tagline'] }}</p>
        @endif
    </div>
    <div class="text-right text-xs leading-relaxed text-ink-600">
        @if (filled($brand['address']))<p>{{ $brand['address'] }}</p>@endif
        @if (filled($brand['phone']))<p>{{ $brand['phone'] }}</p>@endif
        @if (filled($brand['email']))<p>{{ $brand['email'] }}</p>@endif
        @if (filled($brand['website']))<p>{{ $brand['website'] }}</p>@endif
    </div>
</header>

<div class="mt-6 flex flex-wrap items-start justify-between gap-6">
    <div>
        <h1 class="text-2xl font-black text-ink-950">Invoice</h1>
        <p class="mt-1 font-mono text-sm font-bold text-brand-700">{{ $invoice->number }}</p>
        @if ($stamp)
            <span class="mt-2 inline-flex rounded-full px-3 py-1 text-xs font-black uppercase tracking-wide ring-1 {{ $stamp[1] }}">
                {{ $stamp[0] }}
            </span>
        @endif
    </div>
    <dl class="text-right text-sm">
        @if ($invoice->issued_on)
            <div class="flex justify-end gap-3">
                <dt class="text-ink-500">Issued</dt>
                <dd class="font-semibold text-ink-900">{{ $invoice->issued_on->format('j M Y') }}</dd>
            </div>
        @endif
        @if ($invoice->due_on)
            <div class="mt-1 flex justify-end gap-3">
                <dt class="text-ink-500">Due</dt>
                <dd class="font-black text-ink-950">{{ $invoice->due_on->format('j M Y') }}</dd>
            </div>
        @endif
    </dl>
</div>

<section class="avoid-break mt-8 grid gap-6 sm:grid-cols-2">
    <div>
        <h2 class="text-xs font-black uppercase tracking-[0.12em] text-brand-700">Billed to</h2>
        <div class="mt-2 text-sm">
            @if ($invoice->company_name)
                <p class="font-bold text-ink-900">{{ $invoice->company_name }}</p>
                <p class="text-ink-600">Attn: {{ $invoice->contact_name }}</p>
            @else
                <p class="font-bold text-ink-900">{{ $invoice->contact_name }}</p>
            @endif
            @if ($invoice->contact_email)<p class="text-ink-600">{{ $invoice->contact_email }}</p>@endif
            @if ($invoice->contact_phone)<p class="text-ink-600">{{ $invoice->contact_phone }}</p>@endif
        </div>
    </div>
    <div>
        <h2 class="text-xs font-black uppercase tracking-[0.12em] text-brand-700">Subject</h2>
        <p class="mt-2 text-sm font-bold text-ink-900">{{ $invoice->title }}</p>
        @if ($invoice->quotation)
            <p class="mt-1 text-xs text-ink-500">From quotation {{ $invoice->quotation->number }}</p>
        @endif
    </div>
</section>

<section class="mt-8">
    <h2 class="text-xs font-black uppercase tracking-[0.12em] text-brand-700">Charges</h2>

    {{-- Its own scroller: on a phone a four-column money table cannot shrink to
         fit without the figures becoming unreadable, and the page itself must
         never scroll sideways. Printing ignores this and lays the table out
         full width. --}}
    <div class="mt-2 overflow-x-auto">
        <table class="w-full min-w-[34rem] border-collapse text-sm">
            <thead>
                <tr class="bg-ink-100 text-left text-[11px] uppercase tracking-wide text-ink-600">
                    <th class="border-b border-ink-300 px-3 py-2 font-bold">Description</th>
                    <th class="border-b border-ink-300 px-3 py-2 text-right font-bold">Qty</th>
                    <th class="border-b border-ink-300 px-3 py-2 text-right font-bold">Unit price</th>
                    <th class="border-b border-ink-300 px-3 py-2 text-right font-bold">Amount</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($invoice->items as $item)
                    <tr>
                        <td class="border-b border-ink-200 px-3 py-2 text-ink-800">{{ $item->description }}</td>
                        <td class="border-b border-ink-200 px-3 py-2 text-right tabular-nums text-ink-700">{{ $item->quantityLabel() }}</td>
                        <td class="border-b border-ink-200 px-3 py-2 text-right tabular-nums text-ink-700">{{ $item->formattedUnitPrice($invoice->currency) }}</td>
                        <td class="border-b border-ink-200 px-3 py-2 text-right font-semibold tabular-nums text-ink-900">{{ $item->formattedLineTotal($invoice->currency) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</section>

<section class="avoid-break mt-6 flex justify-end">
    <dl class="w-full max-w-xs text-sm">
        <div class="flex justify-between py-1">
            <dt class="text-ink-500">Subtotal</dt>
            <dd class="tabular-nums text-ink-800">{{ $invoice->formattedSubtotal() }}</dd>
        </div>
        @if ($invoice->hasDiscount())
            <div class="flex justify-between py-1">
                <dt class="text-ink-500">Discount</dt>
                <dd class="tabular-nums text-ink-800">&minus;{{ $invoice->formattedDiscount() }}</dd>
            </div>
        @endif
        @if ($invoice->hasTax())
            <div class="flex justify-between py-1">
                <dt class="text-ink-500">{{ $invoice->taxLabel() }}</dt>
                <dd class="tabular-nums text-ink-800">{{ $invoice->formattedTax() }}</dd>
            </div>
        @endif
        <div class="mt-1 flex justify-between border-t-2 border-ink-900 py-2">
            <dt class="font-black text-ink-950">Total</dt>
            <dd class="text-lg font-black tabular-nums text-brand-800">{{ $invoice->formattedTotal() }}</dd>
        </div>
        @if ($settled > 0)
            <div class="flex justify-between py-1">
                <dt class="text-ink-500">Received</dt>
                <dd class="tabular-nums text-ink-800">&minus;{{ $invoice->formattedSettled() }}</dd>
            </div>
            <div class="flex justify-between border-t-2 border-ink-900 py-2">
                <dt class="font-black text-ink-950">Balance due</dt>
                <dd class="text-lg font-black tabular-nums text-brand-800">{{ $invoice->formattedOutstanding() }}</dd>
            </div>
        @endif
    </dl>
</section>

@if ($invoice->notes)
    <section class="avoid-break mt-8">
        <h2 class="text-xs font-black uppercase tracking-[0.12em] text-brand-700">Notes</h2>
        <p class="mt-2 whitespace-pre-line text-sm text-ink-700">{{ $invoice->notes }}</p>
    </section>
@endif

@if ($invoice->terms)
    <section class="avoid-break mt-6">
        <h2 class="text-xs font-black uppercase tracking-[0.12em] text-brand-700">Payment terms</h2>
        <p class="mt-2 whitespace-pre-line text-sm text-ink-700">{{ $invoice->terms }}</p>
    </section>
@endif

<aside class="avoid-break mt-8 border-l-4 border-amber-500 bg-ink-50 px-4 py-3 text-sm text-ink-700">
    @switch($invoice->status)
        @case(InvoiceStatus::Paid)
            This invoice has been paid in full. Thank you.
            @break
        @case(InvoiceStatus::Void)
            This invoice has been voided and is no longer payable.
            @break
        @case(InvoiceStatus::Cancelled)
            This invoice has been cancelled and is no longer payable.
            @break
        @default
            Quote invoice number <strong class="font-black">{{ $invoice->number }}</strong> with your payment.
            @if ($invoice->due_on)
                Payment is due by {{ $invoice->due_on->format('j F Y') }}.
            @endif
    @endswitch
</aside>

<footer class="mt-8 flex flex-wrap justify-between gap-3 border-t border-ink-200 pt-4 text-[11px] text-ink-500">
    <p>
        {{-- The registered name, which a limited company's
             invoices and quotations must carry. --}}
        {{ $brand['legal_name'] ?? $brand['name'] }}
        @if (filled($brand['registration_number'] ?? null)) &middot; Reg. {{ $brand['registration_number'] }} @endif
        @if (filled($brand['tax_identification_number'] ?? null)) &middot; TIN {{ $brand['tax_identification_number'] }} @endif
    </p>
    <p>
        Printed {{ now()->timezone(config('pisfa.business_timezone', 'Africa/Kampala'))->format('j M Y, H:i') }}
        ({{ config('pisfa.business_timezone', 'Africa/Kampala') }})
    </p>
</footer>
