@props(['status', 'overdue' => false])

@php
    use App\Enums\InvoiceStatus;
    use App\Enums\QuotationStatus;

    // Overdue is derived, never a stored status, so it is shown as its own
    // badge rather than replacing the real one.
    $tone = match (true) {
        $status instanceof QuotationStatus => match ($status) {
            QuotationStatus::Accepted => 'emerald',
            QuotationStatus::Sent => 'sky',
            QuotationStatus::Draft => 'slate',
            QuotationStatus::Declined, QuotationStatus::Cancelled => 'rose',
            QuotationStatus::Expired => 'amber',
        },
        $status instanceof InvoiceStatus => match ($status) {
            InvoiceStatus::Paid => 'emerald',
            InvoiceStatus::Issued => 'sky',
            InvoiceStatus::PartiallyPaid => 'amber',
            InvoiceStatus::Draft => 'slate',
            InvoiceStatus::Cancelled, InvoiceStatus::Void => 'rose',
        },
        default => 'slate',
    };
@endphp

<span class="inline-flex flex-wrap items-center gap-1.5">
    <span @class([
        'rounded-full px-3 py-1 text-xs font-bold',
        'bg-emerald-50 text-emerald-800' => $tone === 'emerald',
        'bg-sky-50 text-sky-800' => $tone === 'sky',
        'bg-amber-50 text-amber-900' => $tone === 'amber',
        'bg-rose-50 text-rose-800' => $tone === 'rose',
        'bg-slate-100 text-slate-700' => $tone === 'slate',
    ])>{{ $status->label() }}</span>

    @if ($overdue)
        <span class="rounded-full bg-rose-100 px-3 py-1 text-xs font-bold text-rose-900">Overdue</span>
    @endif
</span>
