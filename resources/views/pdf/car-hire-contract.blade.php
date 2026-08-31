@extends('pdf.layout')

@php
    use App\Support\Money;

    $timezone = config('pisfa.business_timezone', 'Africa/Kampala');
    $snapshot = (array) ($contract->snapshot ?? []);
@endphp

@section('title', 'Vehicle Rental Contract')
@section('reference', $contract->contract_number.' · Version '.$contract->version)

@section('meta')
    Issued {{ $contract->issued_at->timezone($timezone)->format('j M Y') }}<br>
    Booking {{ $booking->reference }}<br>
    @if ($contract->isVoided())
        <strong style="color:#b91c1c;">VOIDED</strong>
    @elseif ($contract->isAccepted())
        <strong style="color:#047857;">ACCEPTED</strong>
    @else
        <strong style="color:#b45309;">AWAITING ACCEPTANCE</strong>
    @endif
@endsection

@section('content')
    <h2>Parties</h2>
    <table>
        <tr>
            <td style="width:50%; vertical-align:top; padding-right:10pt;">
                <div class="muted" style="font-size:9pt;">Owner / Operator</div>
                <div style="font-weight:bold;">{{ $brand['name'] }}</div>
                <div style="font-size:9pt;">{{ $brand['address'] }}</div>
                <div style="font-size:9pt;">{{ $brand['phone'] }}</div>
            </td>
            <td style="width:50%; vertical-align:top;">
                <div class="muted" style="font-size:9pt;">Hirer</div>
                <div style="font-weight:bold;">{{ $booking->contact_name }}</div>
                <div style="font-size:9pt;">{{ $booking->contact_email }}</div>
                <div style="font-size:9pt;">{{ $booking->contact_phone }}</div>
            </td>
        </tr>
    </table>

    <h2>Vehicle and hire period</h2>
    <table class="pairs">
        <tr>
            <td class="label">Vehicle</td>
            <td class="value">{{ $booking->vehicle_name_snapshot }}</td>
        </tr>
        <tr>
            <td class="label">Hire mode</td>
            <td class="value">{{ $booking->hire_mode->label() }}</td>
        </tr>
        <tr>
            <td class="label">Pickup</td>
            <td class="value">{{ $booking->pickup_at->timezone($timezone)->format('l, j F Y \a\t H:i') }}</td>
        </tr>
        <tr>
            <td class="label">Return</td>
            <td class="value">{{ $booking->return_at->timezone($timezone)->format('l, j F Y \a\t H:i') }}</td>
        </tr>
        <tr>
            <td class="label">Pickup location</td>
            <td class="value">{{ $booking->pickup_location }}</td>
        </tr>
    </table>

    <h2>Charges</h2>
    <table class="data">
        <thead>
            <tr>
                <th>Description</th>
                <th class="num">Amount</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>Daily rate ({{ $booking->hire_mode->label() }})</td>
                <td class="num">{{ Money::format((int) $booking->daily_rate_minor, $booking->currency) }}</td>
            </tr>
            @if ((int) $booking->security_deposit_minor > 0)
                <tr>
                    <td>Refundable security deposit</td>
                    <td class="num">{{ Money::format((int) $booking->security_deposit_minor, $booking->currency) }}</td>
                </tr>
            @endif
        </tbody>
    </table>

    <table class="totals">
        <tr class="grand">
            <td>Total hire charge</td>
            <td class="num">{{ Money::format((int) $booking->total_minor, $booking->currency) }}</td>
        </tr>
    </table>

    <h2>Terms and conditions</h2>
    <div style="font-size:9.5pt; line-height:1.6; white-space:pre-line;">{{ $contract->terms_snapshot }}</div>

    @if ($contract->isAccepted())
        <div class="notice">
            <strong>Acceptance recorded.</strong>
            Accepted on
            {{ $contract->accepted_at->timezone($timezone)->format('l, j F Y \a\t H:i') }}
            ({{ $timezone }}) against this exact contract version.
            This electronic acceptance has the same effect as a signature.
        </div>
    @else
        <table class="signature">
            <tr>
                <td class="muted" style="font-size:8.5pt;">Hirer signature and date</td>
                <td class="gap"></td>
                <td class="muted" style="font-size:8.5pt;">For and on behalf of {{ $brand['name'] }}</td>
            </tr>
        </table>
    @endif

    <div class="notice">
        This document reflects contract version {{ $contract->version }}. Any later
        version supersedes it. Retain this copy for the duration of the hire.
    </div>
@endsection
