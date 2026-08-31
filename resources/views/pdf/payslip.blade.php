@extends('pdf.layout')

@php
    use App\Enums\PayrollRunStatus;

    $currency = $line->currency;
@endphp

@section('title', 'Payslip')
@section('reference', $run->monthLabel())

@section('meta')
    Run {{ $run->reference }}<br>
    Period {{ $run->period_start->format('j M Y') }} — {{ $run->period_end->format('j M Y') }}<br>
    @if ($run->status === PayrollRunStatus::Paid)
        <strong style="color:#047857;">PAID</strong>
    @else
        <strong style="color:#b45309;">APPROVED</strong>
    @endif
@endsection

@section('content')
    <h2>Employee</h2>
    <table>
        <tr>
            <td style="width:60%; vertical-align:top;">
                <div style="font-weight:bold;">{{ $line->employee_name_snapshot }}</div>
                <div style="font-size:9pt;">{{ $line->role_snapshot->label() }}</div>
            </td>
            <td style="width:40%; vertical-align:top;">
                <div class="muted" style="font-size:9pt;">Net pay</div>
                <div style="font-weight:bold; font-size:14pt;">{{ $line->formattedNet() }}</div>
            </td>
        </tr>
    </table>

    <h2>Earnings</h2>
    <table class="data">
        <thead>
            <tr>
                <th style="width:70%;">Description</th>
                <th class="num" style="width:30%;">Amount</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>Basic salary</td>
                <td class="num">{{ $line->formattedGross() }}</td>
            </tr>
            @if ($line->allowances_minor > 0)
                <tr>
                    <td>Allowances</td>
                    <td class="num">{{ $line->formattedAllowances() }}</td>
                </tr>
            @endif
        </tbody>
    </table>

    <table class="totals">
        <tr>
            <td>Total earnings</td>
            <td class="num"><strong>{{ $line->formattedEarnings() }}</strong></td>
        </tr>
    </table>

    <h2>Deductions</h2>
    @if ($line->deductions->isEmpty())
        <p class="muted">Nothing was deducted this period.</p>
    @else
        <table class="data">
            <thead>
                <tr>
                    <th style="width:50%;">Deduction</th>
                    <th class="num" style="width:25%;">Charged on</th>
                    <th class="num" style="width:25%;">Amount</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($line->deductions as $deduction)
                    <tr>
                        <td>{{ $deduction->label }}</td>
                        <td class="num">{{ $deduction->formattedBasis() }}</td>
                        <td class="num">{{ $deduction->formattedAmount() }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <table class="totals">
        <tr>
            <td>Total deductions</td>
            <td class="num">{{ $line->formattedDeductions() }}</td>
        </tr>
        <tr>
            <td><strong>Net pay</strong></td>
            <td class="num"><strong>{{ $line->formattedNet() }}</strong></td>
        </tr>
    </table>

    @if ($line->employer_nssf_minor > 0)
        <p class="muted" style="font-size:9pt; margin-top:10pt;">
            {{ $brand['name'] }} also contributes {{ $line->formattedEmployerNssf() }} to NSSF on your behalf.
            This is paid by the employer and is <strong>not</strong> deducted from your salary.
        </p>
    @endif

    @if ($line->notes)
        <h2>Notes</h2>
        <p>{{ $line->notes }}</p>
    @endif

    <p class="muted" style="font-size:9pt; margin-top:14pt;">
        This payslip is issued in {{ $currency }}. Keep it for your records — it is the statement of what was
        earned and what was deducted for {{ $run->monthLabel() }}.
    </p>
@endsection
