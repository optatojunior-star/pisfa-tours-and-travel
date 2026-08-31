@extends('pdf.layout')

@php
    use App\Enums\InvoiceStatus;

    $timezone = config('pisfa.business_timezone', 'Africa/Kampala');
    $currency = $invoice->currency;
    $settled = $invoice->settledAmountMinor();
@endphp

@section('title', 'Invoice')
@section('reference', $invoice->number)

@section('meta')
    @if ($invoice->issued_on)
        Issued {{ $invoice->issued_on->format('j M Y') }}<br>
    @endif
    @if ($invoice->due_on)
        Due <strong>{{ $invoice->due_on->format('j M Y') }}</strong><br>
    @endif
    @if ($invoice->status === InvoiceStatus::Paid)
        <strong style="color:#047857;">PAID</strong>
    @elseif ($invoice->status === InvoiceStatus::Void)
        <strong style="color:#b91c1c;">VOID</strong>
    @elseif ($invoice->status === InvoiceStatus::Cancelled)
        <strong style="color:#b91c1c;">CANCELLED</strong>
    @elseif ($invoice->status === InvoiceStatus::PartiallyPaid)
        <strong style="color:#b45309;">PART PAID</strong>
    @endif
@endsection

@section('content')
    <h2>Billed to</h2>
    <table>
        <tr>
            <td style="width:55%; vertical-align:top; padding-right:10pt;">
                @if ($invoice->company_name)
                    <div style="font-weight:bold;">{{ $invoice->company_name }}</div>
                    <div style="font-size:9pt;">Attn: {{ $invoice->contact_name }}</div>
                @else
                    <div style="font-weight:bold;">{{ $invoice->contact_name }}</div>
                @endif
                <div style="font-size:9pt;">{{ $invoice->contact_email }}</div>
                <div style="font-size:9pt;">{{ $invoice->contact_phone }}</div>
            </td>
            <td style="width:45%; vertical-align:top;">
                <div class="muted" style="font-size:9pt;">Subject</div>
                <div style="font-weight:bold;">{{ $invoice->title }}</div>
                @if ($invoice->quotation)
                    <div class="muted" style="font-size:9pt; margin-top:4pt;">
                        From quotation {{ $invoice->quotation->number }}
                    </div>
                @endif
            </td>
        </tr>
    </table>

    <h2>Charges</h2>
    <table class="data">
        <thead>
            <tr>
                <th style="width:52%;">Description</th>
                <th class="num" style="width:14%;">Qty</th>
                <th class="num" style="width:17%;">Unit price</th>
                <th class="num" style="width:17%;">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($invoice->items as $item)
                <tr>
                    <td>{{ $item->description }}</td>
                    <td class="num">{{ $item->quantityLabel() }}</td>
                    <td class="num">{{ $item->formattedUnitPrice($currency) }}</td>
                    <td class="num">{{ $item->formattedLineTotal($currency) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr>
            <td style="width:62%;"></td>
            <td class="muted" style="width:20%;">Subtotal</td>
            <td style="width:18%; text-align:right;">{{ $invoice->formattedSubtotal() }}</td>
        </tr>
        @if ($invoice->hasDiscount())
            <tr>
                <td></td>
                <td class="muted">Discount</td>
                <td style="text-align:right;">−{{ $invoice->formattedDiscount() }}</td>
            </tr>
        @endif
        @if ($invoice->hasTax())
            <tr>
                <td></td>
                <td class="muted">{{ $invoice->taxLabel() }}</td>
                <td style="text-align:right;">{{ $invoice->formattedTax() }}</td>
            </tr>
        @endif
        <tr class="grand">
            <td></td>
            <td>Total</td>
            <td style="text-align:right;">{{ $invoice->formattedTotal() }}</td>
        </tr>
        @if ($settled > 0)
            <tr>
                <td></td>
                <td class="muted">Received</td>
                <td style="text-align:right;">−{{ $invoice->formattedSettled() }}</td>
            </tr>
            <tr class="grand">
                <td></td>
                <td>Balance due</td>
                <td style="text-align:right;">{{ $invoice->formattedOutstanding() }}</td>
            </tr>
        @endif
    </table>

    @if ($invoice->notes)
        <h2>Notes</h2>
        <div style="font-size:9.5pt; white-space:pre-line;">{{ $invoice->notes }}</div>
    @endif

    @if ($invoice->terms)
        <h2>Payment terms</h2>
        <div style="font-size:9.5pt; white-space:pre-line;">{{ $invoice->terms }}</div>
    @endif

    <div class="notice">
        @if ($invoice->status === InvoiceStatus::Paid)
            This invoice has been paid in full. Thank you.
        @elseif ($invoice->status === InvoiceStatus::Void)
            This invoice has been voided and is no longer payable.
        @elseif ($invoice->status === InvoiceStatus::Cancelled)
            This invoice has been cancelled and is no longer payable.
        @else
            Quote invoice number <strong>{{ $invoice->number }}</strong> with your payment.
            @if ($invoice->due_on)
                Payment is due by {{ $invoice->due_on->format('j F Y') }}.
            @endif
        @endif
    </div>
@endsection
