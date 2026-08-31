@props(['document'])

@php
    // Works for a Quotation or an Invoice: both expose the same line shape and
    // the same stored totals through HasBillingTotals.
    $currency = $document->currency;
    $isInvoice = $document instanceof \App\Models\Invoice;
    $settled = $isInvoice ? $document->settledAmountMinor() : 0;
@endphp

<div class="overflow-x-auto rounded-2xl border border-slate-200">
    <table class="min-w-full divide-y divide-slate-200 text-sm">
        <caption class="sr-only">Priced items</caption>
        <thead class="bg-stone-50 text-left text-xs uppercase tracking-wide text-slate-500">
            <tr>
                <th scope="col" class="px-4 py-3">Description</th>
                <th scope="col" class="px-4 py-3 text-right">Qty</th>
                <th scope="col" class="px-4 py-3 text-right">Unit price</th>
                <th scope="col" class="px-4 py-3 text-right">Amount</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @foreach ($document->items as $item)
                <tr>
                    <td class="px-4 py-3 text-slate-800">{{ $item->description }}</td>
                    <td class="px-4 py-3 text-right tabular-nums text-slate-600">{{ $item->quantityLabel() }}</td>
                    <td class="px-4 py-3 text-right tabular-nums text-slate-600">{{ $item->formattedUnitPrice($currency) }}</td>
                    <td class="px-4 py-3 text-right font-semibold tabular-nums text-slate-900">{{ $item->formattedLineTotal($currency) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>

<dl class="mt-4 ml-auto max-w-sm space-y-2 text-sm">
    <div class="flex items-center justify-between gap-6">
        <dt class="text-slate-500">Subtotal</dt>
        <dd class="tabular-nums text-slate-800">{{ $document->formattedSubtotal() }}</dd>
    </div>
    @if ($document->hasDiscount())
        <div class="flex items-center justify-between gap-6">
            <dt class="text-slate-500">Discount</dt>
            <dd class="tabular-nums text-slate-800">&minus;{{ $document->formattedDiscount() }}</dd>
        </div>
    @endif
    @if ($document->hasTax())
        <div class="flex items-center justify-between gap-6">
            <dt class="text-slate-500">{{ $document->taxLabel() }}</dt>
            <dd class="tabular-nums text-slate-800">{{ $document->formattedTax() }}</dd>
        </div>
    @endif
    <div class="flex items-center justify-between gap-6 border-t border-slate-300 pt-2">
        <dt class="font-bold text-slate-900">Total</dt>
        <dd class="text-lg font-black tabular-nums text-emerald-800">{{ $document->formattedTotal() }}</dd>
    </div>

    @if ($isInvoice && $settled > 0)
        <div class="flex items-center justify-between gap-6">
            <dt class="text-slate-500">Received</dt>
            <dd class="tabular-nums text-slate-800">&minus;{{ $document->formattedSettled() }}</dd>
        </div>
        <div class="flex items-center justify-between gap-6 border-t border-slate-300 pt-2">
            <dt class="font-bold text-slate-900">Balance due</dt>
            <dd class="text-lg font-black tabular-nums text-amber-700">
                {{ \App\Support\Money::format($document->remainingBalanceMinor(), $currency) }}
            </dd>
        </div>
    @endif

    @if ($isInvoice && $document->hasDeposit() && ! $document->depositIsSettled())
        <div class="rounded-xl bg-amber-50 p-3 text-xs leading-5 text-amber-900">
            This invoice is payable in two stages. The first instalment of
            <span class="font-bold">{{ \App\Support\Money::format((int) $document->deposit_minor, $currency) }}</span>
            is due now; the balance follows.
        </div>
    @endif
</dl>
