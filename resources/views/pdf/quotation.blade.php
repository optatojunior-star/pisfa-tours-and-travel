@extends('pdf.layout')

@php
    $timezone = config('pisfa.business_timezone', 'Africa/Kampala');
    $currency = $quotation->currency;
@endphp

@section('title', 'Quotation')
@section('reference', $quotation->number.($quotation->revision > 1 ? ' · Revision '.$quotation->revision : ''))

@section('meta')
    Issued {{ ($quotation->sent_at ?? now())->timezone($timezone)->format('j M Y') }}<br>
    @if ($quotation->valid_until)
        Valid until <strong>{{ $quotation->valid_until->format('j M Y') }}</strong><br>
    @endif
    @if ($quotation->status === \App\Enums\QuotationStatus::Accepted)
        <strong style="color:#047857;">ACCEPTED</strong>
    @elseif ($quotation->status === \App\Enums\QuotationStatus::Declined)
        <strong style="color:#b91c1c;">DECLINED</strong>
    @elseif ($quotation->status === \App\Enums\QuotationStatus::Expired)
        <strong style="color:#b91c1c;">EXPIRED</strong>
    @endif
@endsection

@section('content')
    <h2>Prepared for</h2>
    <table>
        <tr>
            <td style="width:55%; vertical-align:top; padding-right:10pt;">
                @if ($quotation->company_name)
                    <div style="font-weight:bold;">{{ $quotation->company_name }}</div>
                    <div style="font-size:9pt;">Attn: {{ $quotation->contact_name }}</div>
                @else
                    <div style="font-weight:bold;">{{ $quotation->contact_name }}</div>
                @endif
                <div style="font-size:9pt;">{{ $quotation->contact_email }}</div>
                <div style="font-size:9pt;">{{ $quotation->contact_phone }}</div>
            </td>
            <td style="width:45%; vertical-align:top;">
                <div class="muted" style="font-size:9pt;">Subject</div>
                <div style="font-weight:bold;">{{ $quotation->title }}</div>
            </td>
        </tr>
    </table>

    <h2>Priced items</h2>
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
            @foreach ($quotation->items as $item)
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
            <td style="width:18%; text-align:right;">{{ $quotation->formattedSubtotal() }}</td>
        </tr>
        @if ($quotation->hasDiscount())
            <tr>
                <td></td>
                <td class="muted">Discount</td>
                <td style="text-align:right;">−{{ $quotation->formattedDiscount() }}</td>
            </tr>
        @endif
        @if ($quotation->hasTax())
            <tr>
                <td></td>
                <td class="muted">{{ $quotation->taxLabel() }}</td>
                <td style="text-align:right;">{{ $quotation->formattedTax() }}</td>
            </tr>
        @endif
        <tr class="grand">
            <td></td>
            <td>Total</td>
            <td style="text-align:right;">{{ $quotation->formattedTotal() }}</td>
        </tr>
    </table>

    @if ($quotation->notes)
        <h2>Notes</h2>
        <div style="font-size:9.5pt; white-space:pre-line;">{{ $quotation->notes }}</div>
    @endif

    @if ($quotation->terms)
        <h2>Terms</h2>
        <div style="font-size:9.5pt; white-space:pre-line;">{{ $quotation->terms }}</div>
    @endif

    <div class="notice">
        This is a quotation, not an invoice. No payment is due against this document.
        @if ($quotation->valid_until)
            The prices above stand until {{ $quotation->valid_until->format('j F Y') }}.
        @endif
        Accepting it produces an invoice, which is the only document PISFA collects payment against.
    </div>
@endsection
