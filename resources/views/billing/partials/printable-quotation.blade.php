@php
    use App\Enums\QuotationStatus;

    $timezone = config('pisfa.business_timezone', 'Africa/Kampala');

    $stamp = match ($quotation->status) {
        QuotationStatus::Accepted => ['Accepted', 'bg-emerald-50 text-emerald-800 ring-emerald-200'],
        QuotationStatus::Declined => ['Declined', 'bg-rose-50 text-rose-800 ring-rose-200'],
        QuotationStatus::Expired => ['Expired', 'bg-rose-50 text-rose-800 ring-rose-200'],
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
        <h1 class="text-2xl font-black text-ink-950">Quotation</h1>
        <p class="mt-1 font-mono text-sm font-bold text-brand-700">
            {{ $quotation->number }}@if ($quotation->revision > 1) &middot; Revision {{ $quotation->revision }}@endif
        </p>
        @if ($stamp)
            <span class="mt-2 inline-flex rounded-full px-3 py-1 text-xs font-black uppercase tracking-wide ring-1 {{ $stamp[1] }}">
                {{ $stamp[0] }}
            </span>
        @endif
    </div>
    <dl class="text-right text-sm">
        <div class="flex justify-end gap-3">
            <dt class="text-ink-500">Issued</dt>
            <dd class="font-semibold text-ink-900">
                {{ ($quotation->sent_at ?? now())->timezone($timezone)->format('j M Y') }}
            </dd>
        </div>
        @if ($quotation->valid_until)
            <div class="mt-1 flex justify-end gap-3">
                <dt class="text-ink-500">Valid until</dt>
                <dd class="font-black text-ink-950">{{ $quotation->valid_until->format('j M Y') }}</dd>
            </div>
        @endif
    </dl>
</div>

<section class="avoid-break mt-8 grid gap-6 sm:grid-cols-2">
    <div>
        <h2 class="text-xs font-black uppercase tracking-[0.12em] text-brand-700">Prepared for</h2>
        <div class="mt-2 text-sm">
            @if ($quotation->company_name)
                <p class="font-bold text-ink-900">{{ $quotation->company_name }}</p>
                <p class="text-ink-600">Attn: {{ $quotation->contact_name }}</p>
            @else
                <p class="font-bold text-ink-900">{{ $quotation->contact_name }}</p>
            @endif
            @if ($quotation->contact_email)<p class="text-ink-600">{{ $quotation->contact_email }}</p>@endif
            @if ($quotation->contact_phone)<p class="text-ink-600">{{ $quotation->contact_phone }}</p>@endif
        </div>
    </div>
    <div>
        <h2 class="text-xs font-black uppercase tracking-[0.12em] text-brand-700">Subject</h2>
        <p class="mt-2 text-sm font-bold text-ink-900">{{ $quotation->title }}</p>
    </div>
</section>

<section class="mt-8">
    <h2 class="text-xs font-black uppercase tracking-[0.12em] text-brand-700">Priced items</h2>

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
                @foreach ($quotation->items as $item)
                    <tr>
                        <td class="border-b border-ink-200 px-3 py-2 text-ink-800">{{ $item->description }}</td>
                        <td class="border-b border-ink-200 px-3 py-2 text-right tabular-nums text-ink-700">{{ $item->quantityLabel() }}</td>
                        <td class="border-b border-ink-200 px-3 py-2 text-right tabular-nums text-ink-700">{{ $item->formattedUnitPrice($quotation->currency) }}</td>
                        <td class="border-b border-ink-200 px-3 py-2 text-right font-semibold tabular-nums text-ink-900">{{ $item->formattedLineTotal($quotation->currency) }}</td>
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
            <dd class="tabular-nums text-ink-800">{{ $quotation->formattedSubtotal() }}</dd>
        </div>
        @if ($quotation->hasDiscount())
            <div class="flex justify-between py-1">
                <dt class="text-ink-500">Discount</dt>
                <dd class="tabular-nums text-ink-800">&minus;{{ $quotation->formattedDiscount() }}</dd>
            </div>
        @endif
        @if ($quotation->hasTax())
            <div class="flex justify-between py-1">
                <dt class="text-ink-500">{{ $quotation->taxLabel() }}</dt>
                <dd class="tabular-nums text-ink-800">{{ $quotation->formattedTax() }}</dd>
            </div>
        @endif
        <div class="mt-1 flex justify-between border-t-2 border-ink-900 py-2">
            <dt class="font-black text-ink-950">Total</dt>
            <dd class="text-lg font-black tabular-nums text-brand-800">{{ $quotation->formattedTotal() }}</dd>
        </div>
    </dl>
</section>

@if ($quotation->notes)
    <section class="avoid-break mt-8">
        <h2 class="text-xs font-black uppercase tracking-[0.12em] text-brand-700">Notes</h2>
        <p class="mt-2 whitespace-pre-line text-sm text-ink-700">{{ $quotation->notes }}</p>
    </section>
@endif

@if ($quotation->terms)
    <section class="avoid-break mt-6">
        <h2 class="text-xs font-black uppercase tracking-[0.12em] text-brand-700">Terms</h2>
        <p class="mt-2 whitespace-pre-line text-sm text-ink-700">{{ $quotation->terms }}</p>
    </section>
@endif

{{-- Said plainly, because a printed quotation with a total on it is the exact
     document somebody pays against by mistake. --}}
<aside class="avoid-break mt-8 border-l-4 border-amber-500 bg-ink-50 px-4 py-3 text-sm text-ink-700">
    This is a quotation, not an invoice. No payment is due against this document.
    @if ($quotation->valid_until)
        The prices above stand until {{ $quotation->valid_until->format('j F Y') }}.
    @endif
    Accepting it produces an invoice, which is the only document {{ $brand['name'] }} collects payment against.
</aside>

<section class="avoid-break mt-10 grid gap-8 sm:grid-cols-2">
    <div>
        <div class="h-12 border-b border-ink-400"></div>
        <p class="mt-1 text-[11px] text-ink-500">Accepted for the customer &mdash; name and signature</p>
    </div>
    <div>
        <div class="h-12 border-b border-ink-400"></div>
        <p class="mt-1 text-[11px] text-ink-500">Date</p>
    </div>
</section>

<footer class="mt-8 flex flex-wrap justify-between gap-3 border-t border-ink-200 pt-4 text-[11px] text-ink-500">
    <p>
        {{-- The registered name, which a limited company's
             invoices and quotations must carry. --}}
        {{ $brand['legal_name'] ?? $brand['name'] }}
        @if (filled($brand['registration_number'] ?? null)) &middot; Reg. {{ $brand['registration_number'] }} @endif
        @if (filled($brand['tax_identification_number'] ?? null)) &middot; TIN {{ $brand['tax_identification_number'] }} @endif
    </p>
    <p>
        Printed {{ now()->timezone($timezone)->format('j M Y, H:i') }} ({{ $timezone }})
    </p>
</footer>
